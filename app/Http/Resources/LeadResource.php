<?php

namespace App\Http\Resources;

use App\Domains\Webhooks\WebhookPayload;
use App\Models\Icp;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lead as webhooks send it, plus what an API client needs to sync it.
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /** Eager loads for a page of leads. */
    public const array WITH = ['icp:id,name', 'project:id,name', 'sources:id,lead_id,driver', 'icps:id,name', 'whatsappConversations:id', 'pendingWhatsAppSends'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...WebhookPayload::lead($this->resource),
            'icps' => $this->icps->map(fn (Icp $icp) => ['id' => $icp->id, 'name' => $icp->name])->values(),
            'conversation_ids' => $this->whatsappConversations->pluck('id')->values(),
            'whatsapp_scheduled_for' => $this->pendingWhatsAppSends->first()?->raw_payload['scheduled_for'] ?? null,
            'enriched_at' => $this->enriched_at?->toIso8601String(),
            'scored_at' => $this->scored_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
