<?php

namespace App\Models;

use App\Enums\SearchJobStatus;
use Database\Factories\SearchJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $icp_id
 * @property string $driver
 * @property SearchJobStatus $status
 * @property array<string, mixed> $params
 * @property int $results_count
 * @property int $requests_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property-read Icp $icp
 */
#[Fillable(['icp_id', 'driver', 'status', 'params', 'results_count', 'requests_count', 'started_at', 'finished_at', 'error'])]
class SearchJob extends Model
{
    /** @use HasFactory<SearchJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SearchJobStatus::class,
            'params' => 'array',
            'results_count' => 'integer',
            'requests_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Icp, $this> */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    public function isPending(): bool
    {
        return $this->status === SearchJobStatus::Pending;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [SearchJobStatus::Done, SearchJobStatus::Failed], true);
    }
}
