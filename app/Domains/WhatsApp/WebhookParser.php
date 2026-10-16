<?php

namespace App\Domains\WhatsApp;

use App\Domains\WhatsApp\Data\WhatsAppMessageData;
use App\Domains\WhatsApp\Data\WhatsAppStatusUpdate;
use App\Enums\WhatsAppMessageStatus;
use App\Enums\WhatsAppMessageType;

/**
 * Reads Evolution API v2 webhook bodies: { event, instance, data }.
 * messages.upsert / send.message carry messages, messages.update carries acks.
 * Groups, broadcasts, newsletters and protocol messages (reactions, deletes) are dropped.
 */
class WebhookParser
{
    /** Message kinds that are not chat content. */
    private const IGNORED = ['protocolMessage', 'reactionMessage', 'senderKeyDistributionMessage', 'messageContextInfo', 'pollUpdateMessage', 'editedMessage'];

    /** Wrappers whose content sits in ->message. */
    private const WRAPPERS = ['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension', 'documentWithCaptionMessage'];

    /**
     * "MESSAGES_UPSERT" and "messages.upsert" are the same event, depending on the server version.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function event(array $payload): string
    {
        return str_replace('_', '.', strtolower((string) ($payload['event'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<WhatsAppMessageData>
     */
    public function messages(array $payload): array
    {
        if (! in_array(self::event($payload), ['messages.upsert', 'send.message'], true)) {
            return [];
        }

        $messages = [];

        foreach ($this->items($payload['data'] ?? null, 'messages') as $item) {
            if ($message = $this->message($item)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<WhatsAppStatusUpdate>
     */
    public function statuses(array $payload): array
    {
        if (self::event($payload) !== 'messages.update') {
            return [];
        }

        $updates = [];

        foreach ($this->items($payload['data'] ?? null, 'updates') as $item) {
            $id = $item['keyId'] ?? $item['key']['id'] ?? null;
            $status = WhatsAppMessageStatus::fromEvolution($item['status'] ?? $item['update']['status'] ?? null);

            if (is_string($id) && $id !== '' && $status !== null) {
                $updates[] = new WhatsAppStatusUpdate($id, $status);
            }
        }

        return $updates;
    }

    /**
     * Builds the message Evolution echoes back from POST /message/sendText.
     *
     * @param  array<string, mixed>  $response
     */
    public function sent(array $response, string $text): ?WhatsAppMessageData
    {
        $message = $this->message($response);

        if ($message === null) {
            return null;
        }

        return new WhatsAppMessageData(
            externalId: $message->externalId,
            remoteJid: $message->remoteJid,
            phone: $message->phone,
            fromMe: true,
            type: WhatsAppMessageType::Text,
            body: $message->body ?? $text,
            status: $message->status ?? WhatsAppMessageStatus::Pending,
            sentAt: $message->sentAt,
        );
    }

    /**
     * data is one item, a list of items, or { <key>: [items] } depending on the server version.
     *
     * @return list<array<string, mixed>>
     */
    private function items(mixed $data, string $key): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['key']) || isset($data['keyId'])) {
            return [$data];
        }

        $list = is_array($data[$key] ?? null) ? $data[$key] : $data;

        return array_values(array_filter($list, 'is_array'));
    }

    /** @param  array<string, mixed>  $item */
    private function message(array $item): ?WhatsAppMessageData
    {
        $key = is_array($item['key'] ?? null) ? $item['key'] : [];
        $id = $key['id'] ?? null;
        $jid = $this->jid($key, $item);

        if (! is_string($id) || $id === '' || $jid === null) {
            return null;
        }

        $content = $this->content(is_array($item['message'] ?? null) ? $item['message'] : []);

        if ($content === null) {
            return null;
        }

        [$type, $body, $mime, $fileName] = $content;
        $fromMe = (bool) ($key['fromMe'] ?? false);

        return new WhatsAppMessageData(
            externalId: $id,
            remoteJid: $jid,
            phone: PhoneNumbers::fromJid($jid),
            fromMe: $fromMe,
            type: $type,
            body: $body,
            mimeType: $mime,
            fileName: $fileName,
            pushName: ! $fromMe && is_string($item['pushName'] ?? null) && $item['pushName'] !== '' ? $item['pushName'] : null,
            status: $fromMe ? (WhatsAppMessageStatus::fromEvolution($item['status'] ?? null) ?? WhatsAppMessageStatus::Sent) : null,
            sentAt: $this->timestamp($item['messageTimestamp'] ?? null),
        );
    }

    /**
     * Person-to-person chats only. Newer servers may address a chat by its LID
     * ("…@lid") and give the phone JID in remoteJidAlt / senderPn.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $item
     */
    private function jid(array $key, array $item): ?string
    {
        $jid = (string) ($key['remoteJid'] ?? '');

        if (str_ends_with($jid, '@lid')) {
            $jid = (string) ($key['remoteJidAlt'] ?? $key['senderPn'] ?? $item['senderPn'] ?? '');
        }

        return str_ends_with($jid, '@s.whatsapp.net') && PhoneNumbers::fromJid($jid) !== '' ? $jid : null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: WhatsAppMessageType, 1: ?string, 2: ?string, 3: ?string}|null type, text/caption, mime, file name
     */
    private function content(array $message): ?array
    {
        foreach (self::WRAPPERS as $wrapper) {
            if (is_array($message[$wrapper]['message'] ?? null)) {
                return $this->content($message[$wrapper]['message']);
            }
        }

        $str = fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return match (true) {
            isset($message['conversation']) => [WhatsAppMessageType::Text, $str($message['conversation']), null, null],
            isset($message['extendedTextMessage']) => [WhatsAppMessageType::Text, $str($message['extendedTextMessage']['text'] ?? null), null, null],
            isset($message['imageMessage']) => [WhatsAppMessageType::Image, $str($message['imageMessage']['caption'] ?? null), $str($message['imageMessage']['mimetype'] ?? null), null],
            isset($message['videoMessage']) => [WhatsAppMessageType::Video, $str($message['videoMessage']['caption'] ?? null), $str($message['videoMessage']['mimetype'] ?? null), null],
            isset($message['audioMessage']) => [WhatsAppMessageType::Audio, null, $str($message['audioMessage']['mimetype'] ?? null), null],
            isset($message['documentMessage']) => [WhatsAppMessageType::Document, $str($message['documentMessage']['caption'] ?? null), $str($message['documentMessage']['mimetype'] ?? null), $str($message['documentMessage']['fileName'] ?? null)],
            isset($message['stickerMessage']) => [WhatsAppMessageType::Sticker, null, $str($message['stickerMessage']['mimetype'] ?? null), null],
            $message === [] || array_diff(array_keys($message), self::IGNORED) === [] => null,
            default => [WhatsAppMessageType::Other, null, null, null],
        };
    }

    /** messageTimestamp comes as int, numeric string or a protobuf Long { low, high }. */
    private function timestamp(mixed $value): int
    {
        if (is_array($value)) {
            $value = $value['low'] ?? null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : time();
    }
}
