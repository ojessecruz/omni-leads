<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lead_id
 * @property string $driver
 * @property string $external_id
 * @property array<string, mixed> $raw_payload
 * @property Carbon|null $updated_at
 */
#[Fillable(['lead_id', 'driver', 'external_id', 'raw_payload'])]
class LeadSource extends Model
{
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
