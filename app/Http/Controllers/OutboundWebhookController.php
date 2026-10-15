<?php

namespace App\Http\Controllers;

use App\Jobs\RecordOutboundEventJob;
use App\Models\OutboundConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Events from the project's cold-mail tool. The connection's secret travels in the
 * URL (Saleshandy signs nothing); the connector turns the body into an event.
 */
class OutboundWebhookController extends Controller
{
    public function __invoke(Request $request, OutboundConnection $connection, string $secret): JsonResponse
    {
        abort_unless(hash_equals($connection->webhook_secret, $secret), 401);

        $event = $connection->connector()->parseWebhook($request->json()->all());

        if ($event !== null) {
            RecordOutboundEventJob::dispatch($connection->id, $event->toArray());
        }

        return response()->json(['ok' => true]);
    }
}
