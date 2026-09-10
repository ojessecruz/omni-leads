<?php

return [
    'default' => 'google_places',

    'drivers' => [
        'google_places' => [
            'enabled' => (bool) env('GOOGLE_PLACES_ENABLED', true),
            'key' => env('GOOGLE_PLACES_KEY'),
            // Places (New) returns at most 20 per page and 60 per text query.
            'max_pages' => (int) env('GOOGLE_PLACES_MAX_PAGES', 3),
            'requests_per_second' => (int) env('GOOGLE_PLACES_RPS', 10),
            // Text Search (Enterprise SKU, with phone/website): ~US$ 35 per 1000 requests.
            'cost_per_request' => (float) env('GOOGLE_PLACES_COST', 0.035),
        ],
        'apollo' => [
            'enabled' => (bool) env('APOLLO_ENABLED', false),
            'key' => env('APOLLO_KEY'),
            'max_pages' => (int) env('APOLLO_MAX_PAGES', 3),
            'per_page' => 100,
            'requests_per_minute' => (int) env('APOLLO_RPM', 50),
            // People search does not consume credits by default; adjust if on a per-request plan.
            'cost_per_request' => (float) env('APOLLO_COST', 0),
        ],
        'cnpj_br' => [
            'enabled' => (bool) env('CNPJ_BR_ENABLED', false),
            'max_results_per_query' => (int) env('CNPJ_BR_MAX_RESULTS', 500),
            'cost_per_request' => 0,
        ],
    ],
];
