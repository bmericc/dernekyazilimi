<?php

return [
    'currency' => 'TRY',

    // Card payment drivers that can be set up at /admin/payment-gateways.
    'drivers' => [
        'iyzico' => \App\Support\Payments\IyzicoGateway::class,
    ],
];
