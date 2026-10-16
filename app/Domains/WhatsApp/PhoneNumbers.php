<?php

namespace App\Domains\WhatsApp;

/**
 * Lead phones and WhatsApp ids do not always spell a number the same way. Brazilian mobiles
 * are the usual case: the WhatsApp id of older accounts has no ninth digit (55 11 8765-4321),
 * while the lead has it (55 11 98765-4321), and leads may be stored without the country code.
 */
final class PhoneNumbers
{
    /** Digits of a WhatsApp id: "5511987654321:12@s.whatsapp.net" → "5511987654321". */
    public static function fromJid(string $jid): string
    {
        $user = explode('@', $jid, 2)[0];

        return preg_replace('/\D+/', '', explode(':', $user, 2)[0]) ?? '';
    }

    /**
     * Every spelling the same number may have among stored lead phones.
     *
     * @return list<string>
     */
    public static function variants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 8) {
            return [];
        }

        $variants = [$digits];

        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $area = substr($digits, 2, 2);
            $local = substr($digits, 4);

            // Ninth digit: present on 9-digit mobiles, missing on 8-digit ones starting with 6-9.
            if (strlen($local) === 9 && $local[0] === '9') {
                $variants[] = '55'.$area.substr($local, 1);
            } elseif (strlen($local) === 8 && $local[0] >= '6') {
                $variants[] = '55'.$area.'9'.$local;
            }

            foreach ($variants as $variant) {
                $variants[] = substr($variant, 2);
            }
        }

        return array_values(array_unique($variants));
    }
}
