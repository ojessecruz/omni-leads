<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contact photo of an inbox conversation, streamed only to its owner. */
class WhatsAppAvatarController extends Controller
{
    public function __invoke(WhatsAppConversation $conversation): StreamedResponse
    {
        abort_unless($conversation->avatar_path !== null && $conversation->user_id === auth()->id(), 404);

        return Storage::response($conversation->avatar_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
