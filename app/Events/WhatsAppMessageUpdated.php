<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The delivery status of a sent message moved (delivered, read): open chats redraw the ticks. */
class WhatsAppMessageUpdated implements ShouldBroadcast
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
        return 'whatsapp.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->message->whatsapp_conversation_id,
            'message_id' => $this->message->id,
            'status' => $this->message->status?->value,
        ];
    }
}
