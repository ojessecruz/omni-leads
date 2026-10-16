<?php

namespace App\Models;

use Database\Factories\WhatsAppConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One chat per WhatsApp number of the user's paired phone. Only numbers that are
 * leads in one of the user's projects get a conversation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $remote_jid
 * @property string $phone
 * @property string|null $name
 * @property string|null $avatar_path profile photo copied from WhatsApp (their URLs expire)
 * @property Carbon|null $avatar_checked_at
 * @property string|null $last_message_preview
 * @property Carbon|null $last_message_at
 * @property int $unread_count
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'remote_jid', 'phone', 'name', 'avatar_path', 'avatar_checked_at', 'last_message_preview', 'last_message_at', 'unread_count'])]
class WhatsAppConversation extends Model
{
    /** @use HasFactory<WhatsAppConversationFactory> */
    use HasFactory;

    protected $table = 'whatsapp_conversations';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'avatar_checked_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /** Photos are looked up again after this many days (people change them). */
    public const AVATAR_TTL_DAYS = 7;

    public function avatarIsStale(): bool
    {
        return $this->avatar_checked_at === null || $this->avatar_checked_at->lt(now()->subDays(self::AVATAR_TTL_DAYS));
    }

    /** WhatsApp profile name once the number wrote; before that, the lead's name; else the number. */
    public function displayName(): string
    {
        return $this->name ?? $this->leads->first()?->displayName() ?? '+'.$this->phone;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Lead, $this> */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'whatsapp_conversation_lead', 'whatsapp_conversation_id');
    }

    /** @return HasMany<WhatsAppMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id');
    }
}
