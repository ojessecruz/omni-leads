<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $product_description
 * @property string|null $problem
 * @property string|null $current_customers
 * @property string|null $avg_ticket
 * @property string|null $whatsapp_template
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OutboundConnection|null $outboundConnection
 */
#[Fillable(['user_id', 'name', 'product_description', 'problem', 'current_customers', 'avg_ticket', 'whatsapp_template'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Icp, $this> */
    public function icps(): HasMany
    {
        return $this->hasMany(Icp::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<IcpDraft, $this> */
    public function icpDrafts(): HasMany
    {
        return $this->hasMany(IcpDraft::class);
    }

    /** @return HasOne<OutboundConnection, $this> */
    public function outboundConnection(): HasOne
    {
        return $this->hasOne(OutboundConnection::class);
    }

    /** @return HasMany<Webhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function hasContext(): bool
    {
        return filled($this->product_description) && filled($this->problem);
    }
}
