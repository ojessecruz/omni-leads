<?php

return [
    // Days before a lead may be enriched again automatically.
    'ttl_days' => (int) env('ENRICHMENT_TTL_DAYS', 30),

    'site_scrape' => [
        'enabled' => (bool) env('SITE_SCRAPE_ENABLED', true),
        'timeout' => 5,
        'paths' => ['/', '/contato', '/contact', '/fale-conosco', '/sobre'],
    ],

    // Hunter and Evolution run when the project owner connected them (Settings > Integrations).
    'hunter' => [
        'requests_per_minute' => (int) env('HUNTER_RPM', 15),
        // Domain search costs 1 credit; Starter plan ≈ US$ 0.098 per credit.
        'cost_per_request' => (float) env('HUNTER_COST', 0.098),
    ],

    'evolution' => [
        // Omni Leads' own Evolution server, offered to users who do not bring one.
        // Leave blank to require every user to bring their own server.
        'base_url' => rtrim((string) env('EVOLUTION_BASE_URL', ''), '/'),
        'key' => env('EVOLUTION_API_KEY'),
        'requests_per_minute' => (int) env('EVOLUTION_RPM', 60),
        'cost_per_request' => (float) env('EVOLUTION_COST', 0),
    ],
];
