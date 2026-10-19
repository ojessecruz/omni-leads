<?php

namespace App\Http\Controllers\Api;

use App\Actions\WhatsApp\ReplyToConversation;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\WhatsAppConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** WhatsApp conversations of the token's user, for bots and automations (n8n and alike). */
class ConversationController extends Controller
{
    /** Conversations whose last message came from the contact, the longest waiting first. */
    public function awaiting(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['integer', 'between:1,100']]);

        $conversations = WhatsAppConversation::query()
            ->where('user_id', $request->user()->id)
            ->awaitingReply()
            ->with('leads.project:id,name')
            ->orderBy('waiting_since')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 25));

        return ConversationResource::collection($conversations);
    }

    /** Messages of a conversation, newest first; follow meta.next_cursor for older ones. */
    public function messages(Request $request, int $conversation): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['integer', 'between:1,100']]);

        $messages = $this->find($request, $conversation)
            ->messages()
            ->latest('sent_at')
            ->latest('id')
            ->cursorPaginate($request->integer('per_page', 50));

        return MessageResource::collection($messages);
    }

    /** Queues a text reply; it shows up in the messages once WhatsApp accepts it. */
    public function reply(Request $request, int $conversation, ReplyToConversation $reply): JsonResponse
    {
        $model = $this->find($request, $conversation);
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        try {
            $reply($model, trim($data['text']));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        return response()->json(['status' => 'queued'], 202);
    }

    private function find(Request $request, int $id): WhatsAppConversation
    {
        return WhatsAppConversation::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
