<?php

namespace App\Http\Resources;

use App\Models\Lead;
use App\Models\WhatsAppConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin WhatsAppConversation */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $waitingSince = $this->resource->getAttribute('waiting_since');

        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->name,
            'unread_count' => $this->unread_count,
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'bot_paused' => $this->bot_paused_at !== null,
            'bot_paused_at' => $this->bot_paused_at?->toIso8601String(),
            'waiting_since' => $this->when($waitingSince !== null, fn () => Carbon::parse($waitingSince)->toIso8601String()),
            'leads' => $this->leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->displayName(),
                'status' => $lead->status->value,
                'project' => ['id' => $lead->project->id, 'name' => $lead->project->name],
            ])->values(),
            'url' => route('conversations.index', ['c' => $this->id]),
        ];
    }
}
