<?php

declare(strict_types=1);

namespace App\Infrastructure\Oidc;

use App\Http\Auth\Contracts\JwksKeySetProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class GoogleJwksKeySetProvider implements JwksKeySetProvider
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const KEY_SET_CACHE_KEY = 'oidc.google.jwks.rs256.v1';

    private const UNKNOWN_KID_REFRESH_CACHE_KEY = 'oidc.google.jwks.unknown-kid-refresh.v1';

    private const MAX_JWKS_BYTES = 65536;

    private const MAX_JWKS_KEYS = 10;

    public function keyFor(string $keyId): ?Key
    {
        if (strlen($keyId) > 256 || preg_match('/\A[A-Za-z0-9._-]+\z/D', $keyId) !== 1) {
            return null;
        }

        try {
            $fetched = false;
            $keys = $this->cachedKeySet($fetched);
            if (isset($keys[$keyId])) {
                return $keys[$keyId];
            }

            if ($fetched) {
                return null;
            }

            $refreshSeconds = $this->boundedConfigSeconds('oidc.unknown_kid_refresh_seconds', 60, 10, 300);
            if (! Cache::add(self::UNKNOWN_KID_REFRESH_CACHE_KEY, true, $refreshSeconds)) {
                return null;
            }

            return $this->fetchKeySet()[$keyId] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, Key> */
    private function cachedKeySet(bool &$fetched): array
    {
        $fetched = false;
        $cached = Cache::get(self::KEY_SET_CACHE_KEY);
        if (is_array($cached)) {
            try {
                $keys = $this->parseKeySet($cached);
                if ($keys !== []) {
                    return $keys;
                }
            } catch (Throwable) {
                // Replace invalid cache contents with a fresh, fixed-origin key set.
            }
        }

        $fetched = true;

        return $this->fetchKeySet();
    }

    /** @return array<string, Key> */
    private function fetchKeySet(): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(5)
            ->withOptions(['allow_redirects' => false, 'http_errors' => false])
            ->get(self::JWKS_URL);

        if (! $response->successful()) {
            throw new RuntimeException('Google JWKS unavailable.');
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_JWKS_BYTES) {
            throw new RuntimeException('Google JWKS response exceeds the configured limit.');
        }

        $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($document)) {
            throw new RuntimeException('Google JWKS response is invalid.');
        }

        $validatedKeySet = $this->validatedKeySet($document);
        $keySet = ['keys' => $validatedKeySet['keys']];
        $keys = $this->parseKeySet($keySet);

        Cache::put(
            self::KEY_SET_CACHE_KEY,
            $keySet,
            $this->boundedConfigSeconds('oidc.jwks_cache_seconds', 300, 30, 3600),
        );

        return $keys;
    }

    /** @param array<string, mixed> $keySet
     * @return array<string, Key>
     */
    private function parseKeySet(array $keySet): array
    {
        $validatedKeySet = $this->validatedKeySet($keySet);
        $parsed = JWK::parseKeySet(['keys' => $validatedKeySet['keys']], 'RS256');

        if (count($parsed) !== count($validatedKeySet['keys'])) {
            throw new RuntimeException('Google JWKS contains an unsupported signing key.');
        }

        foreach ($parsed as $keyId => $key) {
            if (! is_string($keyId) || ! isset($validatedKeySet['key_ids'][$keyId]) || ! $key instanceof Key) {
                throw new RuntimeException('Google JWKS contains a non-RS256 key.');
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $keySet
     * @return array{keys: list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>, key_ids: array<string, true>}
     */
    private function validatedKeySet(array $keySet): array
    {
        $keys = $keySet['keys'] ?? null;
        if (! is_array($keys) || ! array_is_list($keys) || count($keys) > self::MAX_JWKS_KEYS) {
            throw new RuntimeException('Google JWKS response is invalid.');
        }

        $validatedKeys = [];
        $keyIds = [];
        foreach ($keys as $jwk) {
            if (! is_array($jwk)
                || ($jwk['kty'] ?? null) !== 'RSA'
                || ($jwk['use'] ?? null) !== 'sig'
                || ($jwk['alg'] ?? null) !== 'RS256'
                || ! is_string($jwk['kid'] ?? null)
                || preg_match('/\A[A-Za-z0-9._-]{1,256}\z/D', $jwk['kid']) !== 1
                || ! is_string($jwk['n'] ?? null)
                || strlen($jwk['n']) > 4096
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $jwk['n']) !== 1
                || ! is_string($jwk['e'] ?? null)
                || strlen($jwk['e']) > 16
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $jwk['e']) !== 1) {
                throw new RuntimeException('Google JWKS contains an unsupported key.');
            }

            if (isset($keyIds[$jwk['kid']])) {
                throw new RuntimeException('Google JWKS contains duplicate key identifiers.');
            }

            $keyIds[$jwk['kid']] = true;
            $validatedKeys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $jwk['kid'],
                'n' => $jwk['n'],
                'e' => $jwk['e'],
            ];
        }

        if ($validatedKeys === []) {
            throw new RuntimeException('Google JWKS contains no supported signing keys.');
        }

        return ['keys' => $validatedKeys, 'key_ids' => $keyIds];
    }

    private function boundedConfigSeconds(string $key, int $default, int $minimum, int $maximum): int
    {
        $configured = config($key);

        return is_int($configured) ? max($minimum, min($maximum, $configured)) : $default;
    }
}
