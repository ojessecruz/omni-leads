<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Media of an inbox message, streamed only to the owner of the conversation. */
class WhatsAppMediaController extends Controller
{
    public function __invoke(WhatsAppMessage $message): StreamedResponse
    {
        abort_unless($message->media_path !== null && $message->conversation->user_id === auth()->id(), 404);

        return Storage::response($message->media_path, $message->media_name, [
            'Content-Type' => $message->media_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
