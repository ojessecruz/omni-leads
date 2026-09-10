<?php

/*
 * Qualification score weights (0-100). Only criteria applicable to the ICP count:
 * the score is normalized over the sum of applicable weights.
 */
return [
    'weights' => [
        'country' => 20,      // lead country is among the ICP countries
        'has_website' => 20,  // ICP requires a website and the lead has a domain
        'min_reviews' => 15,  // Places reviews >= ICP minimum
        'min_rating' => 15,   // Places rating >= ICP minimum
        'email' => 15,        // e-mail encontrado
        'whatsapp' => 15,     // WhatsApp validado
    ],

    'qualified_threshold' => (int) env('LEAD_QUALIFIED_THRESHOLD', 60),
];
