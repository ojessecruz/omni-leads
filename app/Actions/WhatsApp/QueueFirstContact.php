<?php

namespace App\Actions\WhatsApp;

use App\Enums\IntegrationProvider;
use App\Jobs\SendWhatsAppJob;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Schedules the first WhatsApp message to a lead, from the lead page or the leads list.
 * It goes out in the next first-contact slot (gap, hourly and daily caps, business hours),
 * which protects the number from a restriction. The lead_sources row is written now, so
 * the lead shows as scheduled and cannot be queued twice.
 */
class QueueFirstContact
{
    public function __construct(private FirstContactSlots $slots) {}

    /**
     * @return CarbonImmutable when the message goes out
     *
     * @throws RuntimeException with a message for the user when it cannot be sent
     */
    public function __invoke(Lead $lead, string $text): CarbonImmutable
    {
        if (! $lead->phone) {
            throw new RuntimeException(__('Lead has no phone.'));
        }

        $user = $lead->project->user;

        if (! $user->hasIntegration(IntegrationProvider::Evolution)) {
            throw new RuntimeException(__('WhatsApp is not connected. Pair your phone in Settings > Integrations.'));
        }

        // A lead with a conversation is answered from the inbox, not with a new first message.
        if ($lead->whatsappConversations()->where('user_id', $user->id)->exists()) {
            throw new RuntimeException(__('This lead already has a conversation. Reply from the inbox.'));
        }

        if ($lead->pendingWhatsAppSends()->exists()) {
            throw new RuntimeException(__('A message to this lead is already scheduled.'));
        }

        $at = $this->slots->book($user) ?? throw new RuntimeException(__('The first-contact queue is full for the next days. Try again tomorrow.'));

        $deliveryId = (string) Str::uuid();

        $lead->sources()->create([
            'driver' => 'whatsapp_outbound',
            'external_id' => $deliveryId,
            'raw_payload' => ['text' => $text, 'queued_at' => now()->toIso8601String(), 'scheduled_for' => $at->toIso8601String()],
        ]);

        SendWhatsAppJob::dispatch($lead->id, $text, $deliveryId)->delay($at->isFuture() ? $at : null);

        return $at;
    }

    /** Toast for the user: sent now, or the local time it is scheduled for. */
    public static function feedback(CarbonImmutable $at): string
    {
        if ($at->lessThanOrEqualTo(now()->addMinute())) {
            return __('Message queued. The lead will be marked as contacted after sending.');
        }

        return __('Message scheduled for :time. The lead will be marked as contacted after sending.', ['time' => self::localTime($at)]);
    }

    /** "16/10 14:32" in the business-hours timezone. */
    public static function localTime(CarbonImmutable|string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(config('whatsapp.business_hours.timezone'))->format('d/m H:i');
    }
}
