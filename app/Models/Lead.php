<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $icp_id
 * @property string|null $name
 * @property string|null $company_name
 * @property string|null $domain
 * @property string|null $email
 * @property string|null $phone
 * @property bool|null $whatsapp_valid
 * @property string $country
 * @property string|null $city
 * @property string|null $cnpj
 * @property int $score
 * @property LeadStatus $status
 * @property string $dedup_hash
 * @property Carbon|null $enriched_at
 * @property Carbon|null $scored_at
 * @property-read Project $project
 * @property-read Icp $icp
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'project_id', 'icp_id', 'name', 'company_name', 'domain', 'email', 'phone',
    'whatsapp_valid', 'country', 'city', 'cnpj', 'score', 'status', 'dedup_hash',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // The originating ICP always goes into the pivot, factories included.
        static::created(fn (Lead $lead) => $lead->icps()->syncWithoutDetaching([$lead->icp_id]));
    }

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'whatsapp_valid' => 'boolean',
            'score' => 'integer',
            'enriched_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Icp, $this> */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    /**
     * Every ICP the lead showed up in (icp_id is only the originating one).
     *
     * @return BelongsToMany<Icp, $this, Pivot>
     */
    public function icps(): BelongsToMany
    {
        return $this->belongsToMany(Icp::class)->withPivot('created_at');
    }

    /** @return BelongsToMany<WhatsAppConversation, $this> */
    public function whatsappConversations(): BelongsToMany
    {
        return $this->belongsToMany(WhatsAppConversation::class, 'whatsapp_conversation_lead', 'lead_id', 'whatsapp_conversation_id');
    }

    /** @return HasMany<LeadSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    /**
     * First WhatsApp messages queued and not sent yet (no response, no error).
     *
     * @return HasMany<LeadSource, $this>
     */
    public function pendingWhatsAppSends(): HasMany
    {
        return $this->sources()
            ->where('driver', 'whatsapp_outbound')
            ->whereNull('raw_payload->response')
            ->whereNull('raw_payload->error');
    }

    /** @param  Builder<Lead>  $query */
    public function scopeInIcp(Builder $query, int $icpId): void
    {
        $query->whereHas('icps', fn (Builder $q) => $q->where('icps.id', $icpId));
    }

    /** @param  Builder<Lead>  $query */
    public function scopeFromDriver(Builder $query, string $driver): void
    {
        $query->whereHas('sources', fn (Builder $q) => $q->where('driver', $driver));
    }

    public function displayName(): string
    {
        return $this->company_name ?? $this->name ?? $this->domain ?? '—';
    }

    public function websiteUrl(): ?string
    {
        return $this->domain ? 'https://'.$this->domain : null;
    }
}
