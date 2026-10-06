<?php

declare(strict_types=1);

$csv = static function (string $name): array {
    $value = env($name, '');

    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(
        array_map('trim', explode(',', $value)),
        static fn (string $item): bool => $item !== '',
    ));
};

$audience = env('OIDC_GOOGLE_AUDIENCE');

return [
    'audience' => is_string($audience) && trim($audience) !== '' ? trim($audience) : null,
    'allowed_emails' => array_map('strtolower', $csv('OIDC_GOOGLE_ALLOWED_EMAILS')),
    'allowed_subs' => $csv('OIDC_GOOGLE_ALLOWED_SUBS'),
    'jwks_cache_seconds' => 300,
    'unknown_kid_refresh_seconds' => 60,
];
