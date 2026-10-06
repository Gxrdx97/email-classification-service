<?php

declare(strict_types=1);

use App\Http\Auth\Contracts\JwksKeySetProvider;
use App\Http\Auth\GoogleOidcBearerAuthenticator;
use App\Http\Auth\GoogleOidcBearerMiddleware;
use App\Http\Auth\GoogleOidcPrincipal;
use App\Infrastructure\Oidc\GoogleJwksKeySetProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$makeOidcSigningKeyPair = static function (): array {
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if (! $privateKey instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Could not create synthetic OIDC signing key.');
    }

    $details = openssl_pkey_get_details($privateKey);
    if (! is_array($details) || ! is_string($details['key'] ?? null)) {
        throw new RuntimeException('Could not read synthetic OIDC public key.');
    }

    return [
        'private' => $privateKey,
        'public' => $details['key'],
        'jwk' => [
            'n' => $details['rsa']['n'],
            'e' => $details['rsa']['e'],
        ],
    ];
};

$base64UrlEncode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

describe('autenticação bearer Google OIDC', function () use ($makeOidcSigningKeyPair, $base64UrlEncode): void {
    $keyPair = $makeOidcSigningKeyPair();

    beforeEach(function (): void {
        config([
            'cache.default' => 'array',
            'oidc.audience' => 'synthetic-client-id.apps.googleusercontent.com',
            'oidc.allowed_emails' => ['approved@example.test'],
            'oidc.allowed_subs' => [],
        ]);

        Cache::flush();
    });

    $makeToken = static function (array $overrides = [], ?OpenSSLAsymmetricKey $signingKey = null, string $keyId = 'fixture-key') use (&$keyPair): string {
        $claims = array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => 'synthetic-client-id.apps.googleusercontent.com',
            'exp' => time() + 300,
            'iat' => time() - 10,
            'nbf' => time() - 10,
            'sub' => 'synthetic-google-subject',
            'email' => 'approved@example.test',
            'email_verified' => true,
        ], $overrides);

        return JWT::encode($claims, $signingKey ?? $keyPair['private'], 'RS256', $keyId);
    };

    $fakeKeyProvider = static function () use (&$keyPair): JwksKeySetProvider {
        return new class($keyPair['public']) implements JwksKeySetProvider
        {
            public int $lookups = 0;

            public function __construct(private readonly string $publicKey) {}

            public function keyFor(string $keyId): ?Key
            {
                $this->lookups++;

                return $keyId === 'fixture-key' ? new Key($this->publicKey, 'RS256') : null;
            }
        };
    };

    $middlewareFor = static fn (JwksKeySetProvider $provider): GoogleOidcBearerMiddleware => new GoogleOidcBearerMiddleware(
        new GoogleOidcBearerAuthenticator($provider),
    );

    $send = static function (GoogleOidcBearerMiddleware $middleware, ?string $token): array {
        $server = $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $request = Request::create('/api/private', 'GET', server: $server);
        $response = $middleware->handle($request, static fn () => response()->noContent());

        return [$request, $response];
    };

    it('atribui ao request a identidade verificada, sem sessão nem user auth', function () use ($makeToken, $fakeKeyProvider, $middlewareFor, $send): void {
        $provider = $fakeKeyProvider();
        $middleware = $middlewareFor($provider);
        [$request, $response] = $send($middleware, $makeToken());
        $principal = $request->attributes->get(GoogleOidcBearerMiddleware::PRINCIPAL_ATTRIBUTE);

        expect($response->getStatusCode())->toBe(204)
            ->and($principal)->toBeInstanceOf(GoogleOidcPrincipal::class)
            ->and($principal->subject)->toBe('synthetic-google-subject')
            ->and($principal->email)->toBe('approved@example.test')
            ->and($request->user())->toBeNull()
            ->and($provider->lookups)->toBe(1);
    });

    it('aceita issuer Google sem esquema quando a identidade está autorizada por sub', function () use ($makeToken, $fakeKeyProvider, $middlewareFor, $send): void {
        config(['oidc.allowed_emails' => [], 'oidc.allowed_subs' => ['synthetic-google-subject']]);

        [$request, $response] = $send(
            $middlewareFor($fakeKeyProvider()),
            $makeToken(['iss' => 'accounts.google.com', 'email' => 'another@example.test']),
        );

        expect($response->getStatusCode())->toBe(204)
            ->and($request->attributes->get(GoogleOidcBearerMiddleware::PRINCIPAL_ATTRIBUTE)->subject)
            ->toBe('synthetic-google-subject');
    });

    it('é resolvido pelo container Laravel sem registro de rota ou binding adicional', function () use ($send): void {
        $middleware = app(GoogleOidcBearerMiddleware::class);
        [, $response] = $send($middleware, null);

        expect($response->getStatusCode())->toBe(401);
    });

    it('responde 401 genérico sem bearer e não inclui token ou claims', function () use ($fakeKeyProvider, $middlewareFor, $send): void {
        $token = 'synthetic-token-that-must-not-be-reflected';
        [$request, $response] = $send($middlewareFor($fakeKeyProvider()), null);

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getContent())->toBe('{"message":"Unauthenticated."}')
            ->and($response->getContent())->not->toContain($token)
            ->and($request->attributes->get(GoogleOidcBearerMiddleware::PRINCIPAL_ATTRIBUTE))->toBeNull();
    });

    it('falha fechado quando audience ou allowlist não está configurada', function (string $missing) use ($makeToken, $fakeKeyProvider, $middlewareFor, $send): void {
        $provider = $fakeKeyProvider();
        if ($missing === 'audience') {
            config(['oidc.audience' => null]);
        } else {
            config(['oidc.allowed_emails' => [], 'oidc.allowed_subs' => []]);
        }

        [$request, $response] = $send($middlewareFor($provider), $makeToken());

        expect($response->getStatusCode())->toBe(401)
            ->and($request->attributes->get(GoogleOidcBearerMiddleware::PRINCIPAL_ATTRIBUTE))->toBeNull()
            ->and($provider->lookups)->toBe(0);
    })->with([
        'missing audience' => ['audience'],
        'missing all allowlists' => ['allowlists'],
    ]);

    it('rejeita tokens malformados, alg none e assinaturas inválidas', function () use ($keyPair, $makeToken, $fakeKeyProvider, $middlewareFor, $send, $base64UrlEncode): void {
        $provider = $fakeKeyProvider();
        $middleware = $middlewareFor($provider);
        $unsigned = $base64UrlEncode(json_encode(['alg' => 'none', 'kid' => 'fixture-key'], JSON_THROW_ON_ERROR))
            .'.'.$base64UrlEncode(json_encode(['sub' => 'synthetic-google-subject'], JSON_THROW_ON_ERROR)).'.signature';
        $wrongKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if (! $wrongKey instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create alternate synthetic signing key.');
        }

        foreach (['malformed', $unsigned, $makeToken([], $wrongKey), $makeToken([], $keyPair['private'], 'unknown-key')] as $token) {
            [, $response] = $send($middleware, $token);

            expect($response->getStatusCode())->toBe(401)
                ->and($response->getContent())->toBe('{"message":"Unauthenticated."}')
                ->and($response->getContent())->not->toContain($token);
        }
    });

    it('rejeita issuer, audience, temporalidade e identidade não autorizados', function () use ($makeToken, $fakeKeyProvider, $middlewareFor, $send): void {
        $middleware = $middlewareFor($fakeKeyProvider());
        $invalidClaims = [
            ['iss' => 'https://attacker.example.test'],
            ['aud' => 'another-client-id'],
            ['aud' => ['synthetic-client-id.apps.googleusercontent.com', 'another-client-id']],
            ['exp' => time() - 1],
            ['iat' => time() + 60],
            ['nbf' => time() + 60],
            ['nbf' => null],
            ['exp' => null],
            ['iat' => null],
            ['sub' => '  '],
            ['email_verified' => false],
            ['email' => 'unlisted@example.test'],
        ];

        foreach ($invalidClaims as $claims) {
            [, $response] = $send($middleware, $makeToken($claims));

            expect($response->getStatusCode())->toBe(401)
                ->and($response->getContent())->toBe('{"message":"Unauthenticated."}');
        }
    });

    it('busca JWKS somente no endpoint fixo, reaproveita cache e atualiza uma chave rotacionada com limite', function () use (&$keyPair, $makeOidcSigningKeyPair, $base64UrlEncode): void {
        $rotatedKeyPair = $makeOidcSigningKeyPair();
        $toJwk = static function (string $keyId, array $publicKey) use ($base64UrlEncode): array {
            return [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $keyId,
                'n' => $base64UrlEncode($publicKey['n']),
                'e' => $base64UrlEncode($publicKey['e']),
            ];
        };
        $firstJwks = ['keys' => [$toJwk('old-key', $keyPair['jwk'])]];
        $rotatedJwks = ['keys' => [
            $toJwk('old-key', $keyPair['jwk']),
            $toJwk('new-key', $rotatedKeyPair['jwk']),
        ]];
        $requests = 0;

        Http::fake(function (HttpRequest $request) use (&$requests, $firstJwks, $rotatedJwks) {
            $requests++;

            return Http::response($requests === 1 ? $firstJwks : $rotatedJwks, 200);
        });

        $provider = new GoogleJwksKeySetProvider;
        $oldKey = $provider->keyFor('old-key');
        $cachedOldKey = $provider->keyFor('old-key');
        $newKey = $provider->keyFor('new-key');
        $unknownKey = $provider->keyFor('unknown-key');

        expect($oldKey)->toBeInstanceOf(Key::class)
            ->and($cachedOldKey)->toBeInstanceOf(Key::class)
            ->and($newKey)->toBeInstanceOf(Key::class)
            ->and($unknownKey)->toBeNull()
            ->and($requests)->toBe(2);

        Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://www.googleapis.com/oauth2/v3/certs');
    });
});
