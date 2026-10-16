<?php

namespace App\Events;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new message landed in a WhatsApp conversation (incoming, or sent by the app or the phone).
 * Broadcast to the owner's private channel so open pages update the chat, the unread badge
 * and the notifications without polling.
 */
class WhatsAppMessageStored implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public WhatsAppMessage $message) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->message->conversation->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $conversation = $this->message->conversation;

        return [
            'conversation_id' => $conversation->id,
            'message_id' => $this->message->id,
            'from_me' => $this->message->from_me,
            'name' => $conversation->name ?? $conversation->phone,
            'preview' => $conversation->last_message_preview,
            'unread_total' => (int) WhatsAppConversation::query()->where('user_id', $conversation->user_id)->sum('unread_count'),
        ];
    }
}
