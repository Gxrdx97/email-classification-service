<?php

declare(strict_types=1);

use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Exceptions\OAuthFailure;
use App\Infrastructure\Gmail\ReadOnly\GoogleOAuthProvider;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

describe('consentimento OAuth server-side do Gmail', function () {
    $providerWithHandler = static function (MockHandler $handler): GoogleOAuthProvider {
        $httpClient = new HttpClient([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]);

        return new GoogleOAuthProvider(
            'synthetic-client-id',
            'synthetic-client-secret',
            'https://mail.example.test/oauth/gmail/callback',
            $httpClient,
        );
    };

    it('builds_a_fixed_offline_consent_request_with_only_the_approved_scopes', function () {
        $provider = new GoogleOAuthProvider(
            'synthetic-client-id',
            'synthetic-client-secret',
            'https://mail.example.test/oauth/gmail/callback',
        );

        $state = str_repeat('a', 64);
        $url = $provider->authorizationUrl($state);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['scope'])->toBe(implode(' ', GmailScopes::required()))
            ->and($query['state'])->toBe($state)
            ->and($query['redirect_uri'])->toBe('https://mail.example.test/oauth/gmail/callback')
            ->and($query['access_type'])->toBe('offline')
            ->and($query['prompt'])->toBe('consent')
            ->and($query['scope'])->not->toContain(GmailScopes::MAIL_GOOGLE_COM);
    });

    it('propagates_a_sanitized_failure_when_google_does_not_confirm_revocation', function () use ($providerWithHandler) {
        $token = 'synthetic-refresh-token';
        $provider = $providerWithHandler(new MockHandler([new Response(503)]));
        $failure = null;

        try {
            $provider->revoke($token);
        } catch (OAuthFailure $caught) {
            $failure = $caught;
        }

        if (! $failure instanceof OAuthFailure) {
            throw new RuntimeException('Expected an OAuthFailure for an unconfirmed revocation.');
        }

        expect($failure->getMessage())->toBe('O Google não confirmou a revogação da credencial Gmail.')
            ->and($failure->getPrevious())->toBeNull()
            ->and((string) $failure)->not->toContain($token)
            ->and((string) $failure)->not->toContain('synthetic-client-secret');
    });

    it('sanitizes_sdk_exceptions_without_exposing_secrets_in_the_error_representation', function () use ($providerWithHandler) {
        $token = 'synthetic-refresh-token';
        $sdkSecret = 'synthetic-sdk-secret';
        $handler = new MockHandler([
            new ConnectException(
                "SDK transport failed: {$sdkSecret}",
                new Request('POST', 'https://oauth2.googleapis.com/revoke'),
            ),
        ]);
        $provider = $providerWithHandler($handler);
        $failure = null;

        try {
            $provider->revoke($token);
        } catch (OAuthFailure $caught) {
            $failure = $caught;
        }

        if (! $failure instanceof OAuthFailure) {
            throw new RuntimeException('Expected an OAuthFailure for an SDK transport exception.');
        }

        expect($failure->getMessage())->toBe('Não foi possível revogar a credencial Gmail.')
            ->and($failure->getPrevious())->toBeNull()
            ->and((string) $failure)->not->toContain($token)
            ->and((string) $failure)->not->toContain('synthetic-client-secret')
            ->and((string) $failure)->not->toContain($sdkSecret);
    });
});
