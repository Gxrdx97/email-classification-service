<?php

declare(strict_types=1);

use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Exceptions\OAuthScopeMismatch;
use App\Domain\Capture\Exceptions\OAuthStateRejected;
use App\Domain\Capture\Services\GmailOAuthService;
use App\Infrastructure\Gmail\ReadOnly\LaravelOAuthStateStore;
use App\Infrastructure\Gmail\ReadOnly\LaravelOAuthTokenCipher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use Tests\Unit\Capture\Support\FakeGmailOAuthProvider;
use Tests\Unit\Capture\Support\FakeOAuthConnectionStore;
use Tests\Unit\Capture\Support\FakeOAuthStateStore;
use Tests\Unit\Capture\Support\FakeOAuthTokenCipher;

describe('OAuth Gmail restrito', function () {
    it('requests_and_accepts_only_gmail_modify_and_gmail_labels', function () {
        $provider = new FakeGmailOAuthProvider;
        $states = new FakeOAuthStateStore;
        $connections = new FakeOAuthConnectionStore;
        $service = new GmailOAuthService($provider, $states, $connections, new FakeOAuthTokenCipher);

        $url = $service->begin();
        $connection = $service->complete($provider->state, 'synthetic-auth-code');

        expect($url)->toContain('state='.$provider->state)
            ->and($connection->grantedScopes)->toBe(GmailScopes::required())
            ->and($connections->connection?->refreshTokenCiphertext())->toBe('encrypted:synthetic-refresh-token')
            ->and($states->states)->toBe([]);
    });

    it('rejects_mail_google_com_and_any_extra_scope', function (array $scopes) {
        $provider = new FakeGmailOAuthProvider;
        $provider->scopes = $scopes;
        $service = new GmailOAuthService(
            $provider,
            new FakeOAuthStateStore,
            new FakeOAuthConnectionStore,
            new FakeOAuthTokenCipher,
        );
        $service->begin();

        expect(fn () => $service->complete($provider->state, 'synthetic-auth-code'))
            ->toThrow(OAuthScopeMismatch::class);
    })->with([
        'broad scope' => [[GmailScopes::MODIFY, GmailScopes::LABELS, GmailScopes::MAIL_GOOGLE_COM]],
        'unrequested scope' => [[GmailScopes::MODIFY, GmailScopes::LABELS, 'https://www.googleapis.com/auth/userinfo.email']],
        'missing scope' => [[GmailScopes::MODIFY]],
    ]);

    it('rejects_replayed_or_unknown_oauth_state', function () {
        $provider = new FakeGmailOAuthProvider;
        $service = new GmailOAuthService(
            $provider,
            new FakeOAuthStateStore,
            new FakeOAuthConnectionStore,
            new FakeOAuthTokenCipher,
        );
        $service->begin();
        $state = $provider->state;
        $service->complete($state, 'synthetic-auth-code');

        expect(fn () => $service->complete($state, 'synthetic-auth-code'))
            ->toThrow(OAuthStateRejected::class);
    });

    it('consumes_server_side_state_once_and_ciphers_refresh_tokens', function () {
        $stateHash = hash('sha256', 'synthetic-random-state');
        $states = new LaravelOAuthStateStore(new Repository(new ArrayStore));
        $states->issue($stateHash, (new DateTimeImmutable)->modify('+10 minutes'));
        $cipher = new LaravelOAuthTokenCipher(new Encrypter(str_repeat('x', 32), 'aes-256-cbc'));
        $ciphertext = $cipher->encrypt('synthetic-refresh-token');

        expect($states->consume($stateHash))->toBeTrue()
            ->and($states->consume($stateHash))->toBeFalse()
            ->and($ciphertext)->not->toBe('synthetic-refresh-token')
            ->and($cipher->decrypt($ciphertext))->toBe('synthetic-refresh-token');
    });
});
