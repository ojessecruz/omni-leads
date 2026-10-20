<?php

namespace App\Http\Controllers\Api;

use App\Actions\WhatsApp\MarkConversationRead;
use App\Actions\WhatsApp\ReplyToConversation;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\WhatsAppConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** WhatsApp conversations of the token's user, for bots and automations (n8n and alike). */
class ConversationController extends Controller
{
    /** Every conversation, the latest message first; updated_since lets a bot sync only what changed. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['integer', 'between:1,100'],
            'unread' => ['in:0,1,true,false'],
            'bot_paused' => ['in:0,1,true,false'],
            'phone' => ['string', 'max:30'],
            'lead_id' => ['integer'],
            'updated_since' => ['date'],
        ]);

        $conversations = WhatsAppConversation::query()
            ->where('user_id', $request->user()->id)
            ->when($request->has('unread'), fn ($q) => $request->boolean('unread') ? $q->where('unread_count', '>', 0) : $q->where('unread_count', 0))
            ->when($request->has('bot_paused'), fn ($q) => $request->boolean('bot_paused') ? $q->whereNotNull('bot_paused_at') : $q->whereNull('bot_paused_at'))
            ->when($request->filled('phone'), fn ($q) => $q->where('phone', preg_replace('/\D/', '', (string) $request->string('phone'))))
            ->when($request->filled('lead_id'), fn ($q) => $q->whereHas('leads', fn ($l) => $l->whereKey($request->integer('lead_id'))))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', Carbon::parse((string) $request->string('updated_since'))))
            ->with('leads.project:id,name')
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, int $conversation): ConversationResource
    {
        return new ConversationResource($this->find($request, $conversation)->load('leads.project:id,name'));
    }

    /** Pauses or resumes the bot: while paused, a person is handling the chat. */
    public function update(Request $request, int $conversation): ConversationResource
    {
        $model = $this->find($request, $conversation);
        $data = $request->validate(['bot_paused' => ['required', 'boolean']]);

        $model->update(['bot_paused_at' => $data['bot_paused'] ? ($model->bot_paused_at ?? now()) : null]);

        return new ConversationResource($model->load('leads.project:id,name'));
    }

    /** Marks as read without replying (sends the read receipts). */
    public function read(Request $request, int $conversation, MarkConversationRead $markRead): Response
    {
        $markRead($this->find($request, $conversation));

        return response()->noContent();
    }

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
