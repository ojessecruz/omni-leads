<?php

namespace App\Http\Controllers;

use App\Domains\WhatsApp\Data\WhatsAppMessageData;
use App\Domains\WhatsApp\Data\WhatsAppStatusUpdate;
use App\Domains\WhatsApp\WebhookParser;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Integration;
use App\Models\WhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Events from the user's Evolution instance. The secret travels in the URL (Evolution signs
 * nothing). Messages from numbers that are not leads are dropped right here, so personal
 * chats never reach the queue or the database; acks only go on for messages we stored.
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, Integration $integration, string $secret, WebhookParser $parser, WhatsAppInbox $inbox): JsonResponse
    {
        abort_unless(
            $integration->provider === IntegrationProvider::Evolution
                && hash_equals((string) ($integration->credentials['webhook_secret'] ?? ''), $secret),
            401,
        );

        $payload = $request->json()->all();
        $instance = $payload['instance'] ?? null;

        if (is_string($instance) && $instance !== ($integration->credentials['instance'] ?? null)) {
            return response()->json(['ok' => true]);
        }

        $messages = array_values(array_filter(
            $parser->messages($payload),
            fn (WhatsAppMessageData $m) => $inbox->leadsFor($integration->user, $m->phone) !== [],
        ));

        $statuses = $parser->statuses($payload);

        if ($statuses !== [] && ! WhatsAppMessage::query()->whereIn('external_id', array_map(fn (WhatsAppStatusUpdate $s) => $s->externalId, $statuses))->exists()) {
            $statuses = [];
        }

        if ($messages !== [] || $statuses !== []) {
            ProcessWhatsAppWebhookJob::dispatch(
                $integration->user_id,
                array_map(fn (WhatsAppMessageData $m) => $m->toArray(), $messages),
                array_map(fn (WhatsAppStatusUpdate $s) => $s->toArray(), $statuses),
            );
        }

        return response()->json(['ok' => true]);
    }
}
