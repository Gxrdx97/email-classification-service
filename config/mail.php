<?php

return [
    'default' => env('MAIL_MAILER', 'array'),
    'mailers' => [
        'array' => [
            'transport' => 'array',
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.invalid'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'email-classification-service')),
    ],
];
