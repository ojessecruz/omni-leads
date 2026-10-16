<?php

namespace App\Models;

use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $email
 * @property string $token_hash
 * @property int|null $invited_by
 * @property string|null $locale
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $inviter
 */
#[Fillable(['email', 'token_hash', 'invited_by', 'locale', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    public const int VALID_DAYS = 7;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Creates or renews the invitation for the email. Renewing swaps the token, so older links stop working.
     *
     * @return array{0: Invitation, 1: string} the invitation and the plain token for the link
     */
    public static function issue(string $email, User $inviter): array
    {
        $token = Str::random(48);

        $invitation = self::query()->updateOrCreate(['email' => strtolower(trim($email))], [
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'locale' => app()->getLocale(),
            'expires_at' => now()->addDays(self::VALID_DAYS),
            'accepted_at' => null,
        ]);

        return [$invitation, $token];
    }

    /** The pending invitation behind a link, or null when it is unknown, used or expired. */
    public static function findPending(string $token): ?self
    {
        $invitation = self::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation?->isPending() ? $invitation : null;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && ! $this->expires_at->isFuture();
    }
}
