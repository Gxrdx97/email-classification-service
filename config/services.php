<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.labels',
        ],
    ],
    'google_oidc' => [
        'issuer' => env('GOOGLE_OIDC_ISSUER', 'https://accounts.google.com'),
        'audience' => env('GOOGLE_OIDC_AUDIENCE'),
        'discovery_url' => env('GOOGLE_OIDC_DISCOVERY_URL', 'https://accounts.google.com/.well-known/openid-configuration'),
        'allowed_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_OIDC_ALLOWED_EMAILS', '')),
        ))),
    ],
    'openrouter' => [
        'enabled' => (bool) env('OPENROUTER_ENABLED', false),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
    ],
];
