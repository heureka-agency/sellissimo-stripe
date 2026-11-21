<?php

return [
    'lock_credentials' => env('SELLISSIMO_STRIPE_LOCK_CREDENTIALS', false),

    'secret' => env('SELLISSIMO_STRIPE_SECRET'),
    'webhook_secret' => env('SELLISSIMO_STRIPE_WEBHOOK_SECRET'),
    'publishable_key' => env('SELLISSIMO_STRIPE_PUBLISHABLE_KEY'),
    'presentation' => [], // Site handle => hosted or embedded. Hosted is the default.
];
