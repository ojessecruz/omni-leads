<?php

namespace App\Http\Resources;

use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WhatsAppMessage */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_me' => $this->from_me,
            'sent_via' => $this->sent_via,
            'type' => $this->type->value,
            'body' => $this->body,
            'has_media' => $this->type->hasMedia(),
            'media_mime' => $this->media_mime,
            'media_name' => $this->media_name,
            'status' => $this->status?->value,
            'sent_at' => $this->sent_at->toIso8601String(),
        ];
    }
}
