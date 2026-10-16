<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\IntegrationProvider;
use App\Enums\Theme;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path
 * @property string|null $locale
 * @property Theme $theme
 * @property Carbon|null $onboarding_dismissed_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Integration> $integrations
 */
#[Fillable(['name', 'email', 'password', 'locale', 'theme'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** Mirrors the column default so new instances have a theme before being refreshed. */
    protected $attributes = [
        'theme' => 'dark',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'theme' => Theme::class,
            'onboarding_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Public URL of the profile photo on the default disk (R2 in production), or null to show initials.
     * Without a public domain for the bucket, a signed URL valid for a day is used instead.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        $config = config('filesystems.disks.'.config('filesystems.default'));

        return ($config['driver'] ?? null) === 's3' && blank($config['url'] ?? null)
            ? Storage::temporaryUrl($this->avatar_path, now()->addDay())
            : Storage::url($this->avatar_path);
    }

    /** Admins come from ADMIN_EMAILS (config app.admins), not from the database. */
    public function isAdmin(): bool
    {
        return in_array(strtolower($this->email), config('app.admins', []), true);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Integration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function integration(IntegrationProvider $provider): ?Integration
    {
        return $this->integrations->first(fn (Integration $i) => $i->provider === $provider);
    }

    /**
     * The user's keys for every usable provider, as clients read them
     * (an Evolution server without a paired phone is left out).
     *
     * @return array<string, array<string, string>> provider value => credentials
     */
    public function integrationCredentials(): array
    {
        return $this->integrations
            ->filter(fn (Integration $i) => $i->provider->isUsable($i->credentials))
            ->mapWithKeys(fn (Integration $i) => [$i->provider->value => $i->credentials])
            ->all();
    }

    /** @return array<string, string> empty when the provider is not connected */
    public function credentialsFor(IntegrationProvider $provider): array
    {
        return $this->integrationCredentials()[$provider->value] ?? [];
    }

    public function hasIntegration(IntegrationProvider $provider): bool
    {
        return $this->credentialsFor($provider) !== [];
    }
}
