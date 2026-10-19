<?php

namespace App\Models;

use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $active
 * @property int $failures_count
 * @property int|null $last_status
 * @property Carbon|null $last_delivered_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'url', 'secret', 'events', 'active', 'failures_count', 'last_status', 'last_delivered_at'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    /** Labels are translation keys; use labels(). */
    public const array EVENTS = [
        'lead.qualified' => 'Lead qualified',
        'lead.contacted' => 'Lead marked as contacted',
        'lead.enriched' => 'Lead enriched',
        'search.finished' => 'Search finished',
        'whatsapp.message_received' => 'WhatsApp message received',
        'webhook.test' => 'Test',
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_map(fn (string $label) => __($label), self::EVENTS);
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function listensTo(string $event): bool
    {
        return $this->active && in_array($event, $this->events, true);
    }
}
