<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property IntegrationProvider $provider
 * @property array<string, string> $credentials
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'provider', 'credentials', 'verified_at'])]
#[Hidden(['credentials'])]
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'credentials' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "••••a1b2", enough to tell keys apart without showing them. */
    public function maskedKey(): string
    {
        return '••••'.mb_substr((string) ($this->credentials['key'] ?? ''), -4);
    }
}
