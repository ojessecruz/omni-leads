<?php

namespace App\Enums;

enum WhatsAppMessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Sending'),
            self::Sent => __('Sent'),
            self::Delivered => __('Delivered'),
            self::Read => __('Read'),
            self::Failed => __('Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Sent, self::Delivered => 'zinc',
            self::Read => 'lime',
            self::Failed => 'red',
        };
    }

    /** Evolution / Baileys ack names: PENDING, SERVER_ACK, DELIVERY_ACK, READ, PLAYED, ERROR. */
    public static function fromEvolution(?string $status): ?self
    {
        return match (strtoupper((string) $status)) {
            'PENDING' => self::Pending,
            'SERVER_ACK' => self::Sent,
            'DELIVERY_ACK' => self::Delivered,
            'READ', 'PLAYED' => self::Read,
            'ERROR' => self::Failed,
            default => null,
        };
    }

    /** Acks arrive out of order: a status only replaces a lower one (failed always wins). */
    public function isAfter(?self $other): bool
    {
        if ($other === null || $this === self::Failed) {
            return $this !== $other;
        }

        $order = [self::Pending, self::Sent, self::Delivered, self::Read];

        return $other !== self::Failed && array_search($this, $order, true) > array_search($other, $order, true);
    }
}
