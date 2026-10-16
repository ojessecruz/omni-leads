<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First contacts
    |--------------------------------------------------------------------------
    |
    | Messages to numbers that never replied are what gets a number restricted
    | by WhatsApp. They are paced: a random gap between sends, an hourly cap and
    | a daily cap that grows with the days since the phone was paired (warm-up).
    |
    */

    'first_contact' => [
        'per_hour' => (int) env('WHATSAPP_FIRST_CONTACT_PER_HOUR', 8),

        // Days since pairing => daily cap.
        'per_day' => [
            0 => 10,
            3 => 20,
            7 => (int) env('WHATSAPP_FIRST_CONTACT_PER_DAY', 30),
        ],

        // Random wait between two first contacts, in seconds.
        'gap' => [120, 360],

        // How far ahead a send may be scheduled before the queue counts as full.
        'max_days_ahead' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Business hours
    |--------------------------------------------------------------------------
    |
    | First contacts only go out on these days (ISO, 1 = Monday) and hours.
    | A send queued outside them waits for the next opening.
    |
    */

    'business_hours' => [
        'enabled' => (bool) env('WHATSAPP_BUSINESS_HOURS', true),
        'timezone' => env('WHATSAPP_TIMEZONE', 'America/Sao_Paulo'),
        'days' => [1, 2, 3, 4, 5],
        'from' => 9,
        'until' => 18,
    ],

];
