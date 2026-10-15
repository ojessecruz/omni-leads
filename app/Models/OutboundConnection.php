<?php

namespace App\Models;

use App\Domains\Outbound\Contracts\OutboundConnector;
use App\Domains\Outbound\OutboundConnectorResolver;
use Database\Factories\OutboundConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $connector
 * @property array<string, mixed> $credentials
 * @property string|null $campaign_id
 * @property string|null $campaign_name
 * @property bool $auto_push
 * @property string|null $webhook_id
 * @property string $webhook_secret
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'connector', 'credentials', 'campaign_id', 'campaign_name', 'auto_push', 'webhook_id', 'webhook_secret', 'last_error'])]
#[Hidden(['credentials', 'webhook_secret'])]
class OutboundConnection extends Model
{
    /** @use HasFactory<OutboundConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'auto_push' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function connector(): OutboundConnector
    {
        return app(OutboundConnectorResolver::class)->resolve($this->connector, $this->credentials);
    }

    /** lead_sources.driver for leads pushed through this connector. */
    public function sourceDriver(): string
    {
        return 'outbound_'.$this->connector;
    }

    /** "••••a1b2", enough to tell keys apart without showing them. */
    public function maskedKey(): string
    {
        return '••••'.mb_substr((string) ($this->credentials['api_key'] ?? ''), -4);
    }

    public function isReady(): bool
    {
        return filled($this->campaign_id);
    }
}
