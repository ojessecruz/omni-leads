<?php

namespace App\Models;

use App\Domains\Icp\Data\IcpCriteria;
use App\Enums\IcpStatus;
use Database\Factories\IcpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string $name
 * @property IcpStatus $status
 * @property array<string, mixed> $criteria
 * @property int|null $rerun_every_days
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property-read IcpCriteria $criteria_object
 * @property-read Project $project
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'status', 'criteria', 'rerun_every_days', 'last_run_at', 'next_run_at'])]
class Icp extends Model
{
    /** @use HasFactory<IcpFactory> */
    use HasFactory;

    /** Allowed values for rerun_every_days. */
    public const array RERUN_INTERVALS = [7, 14, 30, 60, 90];

    protected function casts(): array
    {
        return [
            'status' => IcpStatus::class,
            'criteria' => 'array',
            'rerun_every_days' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<SearchJob, $this> */
    public function searchJobs(): HasMany
    {
        return $this->hasMany(SearchJob::class);
    }

    /**
     * Leads that showed up in this ICP (including ones born in another ICP and merged).
     *
     * @return BelongsToMany<Lead, $this, Pivot>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class)->withPivot('created_at');
    }

    /** @return HasMany<Lead, $this> */
    public function originLeads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scheduleNextRun(): void
    {
        $this->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $this->rerun_every_days ? now()->addDays($this->rerun_every_days) : null,
        ])->save();
    }

    /** @return Attribute<IcpCriteria, never> */
    protected function criteriaObject(): Attribute
    {
        return Attribute::get(fn () => IcpCriteria::fromArray($this->criteria ?? []));
    }
}
