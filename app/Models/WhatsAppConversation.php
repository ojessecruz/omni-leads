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
 * @property string|null $last_message_preview
 * @property Carbon|null $last_message_at
 * @property int $unread_count
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'remote_jid', 'phone', 'name', 'last_message_preview', 'last_message_at', 'unread_count'])]
class WhatsAppConversation extends Model
{
    /** @use HasFactory<WhatsAppConversationFactory> */
    use HasFactory;

    protected $table = 'whatsapp_conversations';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
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
