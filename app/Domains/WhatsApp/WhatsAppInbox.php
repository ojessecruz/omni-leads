<?php

namespace App\Domains\WhatsApp;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\Data\WhatsAppMessageData;
use App\Domains\WhatsApp\Data\WhatsAppStatusUpdate;
use App\Enums\LeadStatus;
use App\Enums\WhatsAppMessageStatus;
use App\Events\LeadStatusChanged;
use App\Events\WhatsAppMessageStored;
use App\Events\WhatsAppMessageUpdated;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores the WhatsApp messages of the user's paired phone, but only for numbers that are
 * leads in one of the user's projects: anything else is dropped before touching the
 * database. One conversation per number, linked to every lead with that phone.
 */
class WhatsAppInbox
{
    /** Bigger media (long videos) is left as a placeholder instead of being copied. */
    public const MAX_MEDIA_BYTES = 25 * 1024 * 1024;

    /** Statuses a lead leaves when it answers on WhatsApp. */
    private const REPLY_MOVES = [LeadStatus::New, LeadStatus::Qualified, LeadStatus::Contacted];

    /** @return list<Lead> the user's leads with this phone, in any project */
    public function leadsFor(User $user, string $phone): array
    {
        $variants = PhoneNumbers::variants($phone);

        if ($variants === []) {
            return [];
        }

        return array_values(Lead::query()
            ->whereIn('phone', $variants)
            ->whereHas('project', fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->all());
    }

    /**
     * Saves the message (once: a repeated webhook returns the stored row unchanged) and,
     * for a lead's reply, bumps the unread count and moves its leads to "replied".
     *
     * @param  string|null  $sentVia  "omni" when the app sent it; outgoing messages otherwise count as sent from the phone
     */
    public function record(User $user, WhatsAppMessageData $data, ?string $sentVia = null): ?WhatsAppMessage
    {
        $leads = $this->leadsFor($user, $data->phone);

        if ($leads === []) {
            return null;
        }

        $sentAt = Carbon::createFromTimestamp($data->sentAt ?: time());

        [$message, $created] = DB::transaction(function () use ($user, $data, $leads, $sentAt, $sentVia) {
            $conversation = WhatsAppConversation::createOrFirst(
                ['user_id' => $user->id, 'remote_jid' => $data->remoteJid],
                ['phone' => $data->phone, 'name' => $data->pushName],
            );
            $conversation->leads()->syncWithoutDetaching(array_map(fn (Lead $l) => $l->id, $leads));

            $message = WhatsAppMessage::createOrFirst(
                ['whatsapp_conversation_id' => $conversation->id, 'external_id' => $data->externalId],
                [
                    'from_me' => $data->fromMe,
                    'sent_via' => $data->fromMe ? ($sentVia ?? 'phone') : null,
                    'type' => $data->type,
                    'body' => $data->body,
                    'media_mime' => $data->mimeType,
                    'media_name' => $data->fileName,
                    'status' => $data->status,
                    'sent_at' => $sentAt,
                ],
            );

            if (! $message->wasRecentlyCreated) {
                // The webhook may beat SendWhatsAppJob to it: the app still gets the credit.
                if ($sentVia !== null && $message->from_me && $message->sent_via !== $sentVia) {
                    $message->update(['sent_via' => $sentVia]);
                }

                return [$message, false];
            }

            if ($conversation->last_message_at === null || $sentAt->gte($conversation->last_message_at)) {
                $conversation->fill(['last_message_at' => $sentAt, 'last_message_preview' => $data->preview()]);
            }

            if (! $data->fromMe) {
                $conversation->unread_count++;
                $conversation->name = $data->pushName ?? $conversation->name;
            }

            $conversation->save();

            return [$message, true];
        });

        if ($created) {
            if (! $data->fromMe) {
                $this->markReplied($leads);
            }

            WhatsAppMessageStored::dispatch($message);
        }

        return $message;
    }

    /** Applies a delivery ack to the user's message with that id; acks never move a status back. */
    public function applyStatus(User $user, WhatsAppStatusUpdate $update): void
    {
        $messages = WhatsAppMessage::query()
            ->where('external_id', $update->externalId)
            ->where('from_me', true)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
            ->get();

        foreach ($messages as $message) {
            if ($update->status->isAfter($message->status)) {
                $message->update(['status' => $update->status]);
                WhatsAppMessageUpdated::dispatch($message);
            }
        }
    }

    /**
     * Copies the media of an image/audio/video/document message from Evolution to the default disk.
     *
     * @param  EvolutionClient  $evolution  bound to the conversation owner's credentials
     */
    public function storeMedia(WhatsAppMessage $message, EvolutionClient $evolution): void
    {
        if (! $message->needsMedia()) {
            return;
        }

        $media = $evolution->mediaBase64($message->external_id);
        $base64 = $media['base64'];

        if ((int) (strlen($base64) * 3 / 4) > self::MAX_MEDIA_BYTES) {
            return;
        }

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            return;
        }

        $mime = strtolower(trim(explode(';', $media['mimetype'] ?? $message->media_mime ?? 'application/octet-stream')[0]));
        $extension = (new MimeTypes)->getExtensions($mime)[0] ?? 'bin';
        // Random suffix: the bucket may have a public domain, so paths must not be guessable.
        $path = sprintf('whatsapp/%d/%d/%d-%s.%s', $message->conversation->user_id, $message->whatsapp_conversation_id, $message->id, Str::random(24), $extension);

        Storage::put($path, $bytes);

        $message->update([
            'media_path' => $path,
            'media_mime' => $mime,
            'media_name' => $message->media_name ?? $media['fileName'] ?? null,
        ]);
    }

    /** Largest profile photo copied (WhatsApp serves 640px JPEGs, well below this). */
    public const MAX_AVATAR_BYTES = 2 * 1024 * 1024;

    /**
     * Copies the contact's WhatsApp profile photo to the default disk, replacing the old one.
     * A hidden photo clears it. Always stamps avatar_checked_at, so failures wait for the next round.
     *
     * @param  EvolutionClient  $evolution  bound to the conversation owner's credentials
     */
    public function refreshAvatar(WhatsAppConversation $conversation, EvolutionClient $evolution): void
    {
        $url = $evolution->profilePictureUrl($conversation->phone);
        $path = null;

        if ($url !== null) {
            $response = Http::connectTimeout(3)->timeout(15)->get($url);
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if ($response->successful() && str_starts_with($type, 'image/') && strlen($response->body()) <= self::MAX_AVATAR_BYTES) {
                $extension = (new MimeTypes)->getExtensions($type)[0] ?? 'jpg';
                $path = sprintf('whatsapp/%d/%d/avatar-%s.%s', $conversation->user_id, $conversation->id, Str::random(24), $extension);
                Storage::put($path, $response->body());
            } else {
                $path = $conversation->avatar_path;
            }
        }

        if ($conversation->avatar_path !== null && $conversation->avatar_path !== $path) {
            Storage::delete($conversation->avatar_path);
        }

        $conversation->update(['avatar_path' => $path, 'avatar_checked_at' => now()]);
    }

    /** True once the number has written at least once: replying to it is free of the first-contact limit. */
    public function hasReplied(WhatsAppConversation $conversation): bool
    {
        return $conversation->messages()->where('from_me', false)->exists();
    }

    /**
     * Clears the unread count and returns the incoming messages still to be marked as read
     * on WhatsApp (they are flagged read here, so a second call returns nothing).
     *
     * @return list<array{remoteJid: string, id: string}>
     */
    public function markRead(WhatsAppConversation $conversation): array
    {
        return DB::transaction(function () use ($conversation) {
            $unread = $conversation->messages()->where('from_me', false)->whereNull('status')->pluck('external_id')->all();

            $conversation->messages()->where('from_me', false)->whereNull('status')->update(['status' => WhatsAppMessageStatus::Read]);
            $conversation->update(['unread_count' => 0]);

            return array_map(fn (string $id) => ['remoteJid' => $conversation->remote_jid, 'id' => $id], array_values($unread));
        });
    }

    /** Sending from the inbox counts as contacting the leads that were not contacted yet. */
    public function markContacted(WhatsAppConversation $conversation): void
    {
        foreach ($conversation->leads as $lead) {
            $from = $lead->status;

            if (in_array($from, [LeadStatus::New, LeadStatus::Qualified], true)) {
                $lead->update(['status' => LeadStatus::Contacted]);
                LeadStatusChanged::dispatch($lead, $from);
            }
        }
    }

    /** @param  list<Lead>  $leads */
    private function markReplied(array $leads): void
    {
        foreach ($leads as $lead) {
            $from = $lead->status;

            if (in_array($from, self::REPLY_MOVES, true)) {
                $lead->update(['status' => LeadStatus::Replied]);
                LeadStatusChanged::dispatch($lead, $from);
            }
        }
    }
}
