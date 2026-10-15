<?php

namespace App\Models;

use App\Domains\Outbound\Contracts\OutboundConnector;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Enums\IntegrationProvider;
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
#[Fillable(['project_id', 'connector', 'campaign_id', 'campaign_name', 'auto_push', 'webhook_id', 'webhook_secret', 'last_error'])]
#[Hidden(['webhook_secret'])]
class OutboundConnection extends Model
{
    /** @use HasFactory<OutboundConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'auto_push' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Built with the project owner's key from Settings > Integrations. */
    public function connector(): OutboundConnector
    {
        $provider = IntegrationProvider::from($this->connector);

        return app(OutboundConnectorResolver::class)->resolve($this->connector, $this->project->user->credentialsFor($provider));
    }

    /** lead_sources.driver for leads pushed through this connector. */
    public function sourceDriver(): string
    {
        return 'outbound_'.$this->connector;
    }

    public function isReady(): bool
    {
        return filled($this->campaign_id);
    }
}
