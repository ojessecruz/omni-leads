<?php

namespace App\Enums;

enum WhatsAppMessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Sticker = 'sticker';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text'),
            self::Image => __('Image'),
            self::Audio => __('Audio'),
            self::Video => __('Video'),
            self::Document => __('Document'),
            self::Sticker => __('Sticker'),
            self::Other => __('Unsupported message'),
        };
    }

    /** Media is fetched from Evolution after the message is stored. */
    public function hasMedia(): bool
    {
        return in_array($this, [self::Image, self::Audio, self::Video, self::Document, self::Sticker], true);
    }
}
