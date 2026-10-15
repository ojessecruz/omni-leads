<?php

return [
    /*
     * Cold-mail connectors a project can pick (one per project). Credentials are
     * BYOK: each project stores its own key in outbound_connections, never here.
     */
    'connectors' => [
        'saleshandy' => [
            'base_url' => rtrim((string) env('SALESHANDY_BASE_URL', 'https://open-api.saleshandy.com/v1'), '/'),
            'timeout' => 15,
        ],
    ],

    // Leads sent per API call when pushing a whole project.
    'push_chunk' => 100,
];
