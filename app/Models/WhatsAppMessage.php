<?php

namespace App\Models;

use App\Enums\WhatsAppMessageStatus;
use App\Enums\WhatsAppMessageType;
use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $whatsapp_conversation_id
 * @property string $external_id WhatsApp message id (Evolution key.id)
 * @property bool $from_me
 * @property string|null $sent_via "omni" or "phone" for outgoing messages, null for incoming
 * @property WhatsAppMessageType $type
 * @property string|null $body text, or the media caption
 * @property string|null $media_path path on the default disk, once downloaded
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property WhatsAppMessageStatus|null $status delivery status of outgoing messages
 * @property Carbon $sent_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppConversation $conversation
 */
#[Fillable(['whatsapp_conversation_id', 'external_id', 'from_me', 'sent_via', 'type', 'body', 'media_path', 'media_mime', 'media_name', 'status', 'sent_at'])]
class WhatsAppMessage extends Model
{
    /** @use HasFactory<WhatsAppMessageFactory> */
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'type' => WhatsAppMessageType::class,
            'status' => WhatsAppMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WhatsAppConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    /** True while the media of an image/audio/... message is still to be downloaded. */
    public function needsMedia(): bool
    {
        return $this->type->hasMedia() && $this->media_path === null;
    }
}
