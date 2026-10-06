<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\ReadOnly;

use App\Domain\Capture\Contracts\GmailOAuthProvider as GmailOAuthProviderContract;
use App\Domain\Capture\Data\AccessToken;
use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\GmailOAuthGrant;
use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Data\OAuthSecret;
use App\Domain\Capture\Exceptions\OAuthFailure;
use DateTimeImmutable;
use DateTimeZone;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Resource\Users;
use GuzzleHttp\ClientInterface;
use Throwable;

final readonly class GoogleOAuthProvider implements GmailOAuthProviderContract
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $fixedRedirectUri,
        private ?ClientInterface $httpClient = null,
    ) {
        if (trim($clientId) === '' || trim($clientSecret) === '' || ! $this->validRedirectUri($fixedRedirectUri)) {
            throw new \InvalidArgumentException('A configuração OAuth Gmail é inválida.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        if ($state === '') {
            throw new OAuthFailure('Não foi possível iniciar a autorização Gmail.');
        }

        try {
            $client = $this->client();
            $client->setState($state);

            return $client->createAuthUrl(GmailScopes::required());
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível iniciar a autorização Gmail.');
        }
    }

    public function exchangeCode(string $code): GmailOAuthGrant
    {
        try {
            $client = $this->client();
            $tokens = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable) {
            throw new OAuthFailure('O Gmail recusou a troca do código OAuth.');
        }

        return $this->grant($client, $tokens, true);
    }

    public function refresh(string $refreshToken): GmailOAuthGrant
    {
        if ($refreshToken === '') {
            throw new OAuthFailure('A credencial Gmail não pode ser renovada.');
        }

        try {
            $client = $this->client();
            $tokens = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable) {
            throw new OAuthFailure('A credencial Gmail não pode ser renovada.');
        }

        return $this->grant($client, $tokens, false, $refreshToken);
    }

    public function profile(string $accessToken): GmailMailboxProfile
    {
        try {
            $client = $this->client();
            $client->setAccessToken(['access_token' => $accessToken]);
            $users = (new Gmail($client))->users;
            if (! $users instanceof Users) {
                throw new OAuthFailure('O recurso de perfil Gmail não está disponível.');
            }
            $profile = $users->getProfile('me');
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível validar a conta Gmail autorizada.');
        }

        return new GmailMailboxProfile(
            emailAddress: (string) $profile->getEmailAddress(),
            historyId: (string) $profile->getHistoryId(),
        );
    }

    public function revoke(string $token): void
    {
        if ($token === '') {
            throw new OAuthFailure('A credencial Gmail não pode ser revogada.');
        }

        try {
            if (! $this->client()->revokeToken($token)) {
                throw new OAuthFailure('O Google não confirmou a revogação da credencial Gmail.');
            }
        } catch (OAuthFailure $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível revogar a credencial Gmail.');
        }
    }

    private function client(): Client
    {
        $client = new Client;
        if ($this->httpClient !== null) {
            $client->setHttpClient($this->httpClient);
        }
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->fixedRedirectUri);
        $client->setScopes(GmailScopes::required());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(false);

        return $client;
    }

    /** @param array<string, mixed> $tokens */
    private function grant(Client $client, array $tokens, bool $requireGrantedScopes, ?string $fallbackRefreshToken = null): GmailOAuthGrant
    {
        if (isset($tokens['error']) || ! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            throw new OAuthFailure('A resposta de autorização Gmail não contém credenciais válidas.');
        }

        $expiresIn = filter_var($tokens['expires_in'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($expiresIn) || $expiresIn < 1) {
            throw new OAuthFailure('A validade da credencial Gmail é inválida.');
        }

        $scopeString = $client->getOAuth2Service()->getGrantedScope();
        if (! is_string($scopeString) || trim($scopeString) === '') {
            $scopeString = is_string($tokens['scope'] ?? null) ? $tokens['scope'] : '';
        }
        $scopes = $scopeString === '' ? [] : preg_split('/\s+/', trim($scopeString));
        $scopes = array_values(array_filter($scopes ?: [], 'is_string'));

        if ($requireGrantedScopes && $scopes === []) {
            throw new OAuthFailure('O Google não retornou os escopos concedidos.');
        }

        $refreshToken = $tokens['refresh_token'] ?? $fallbackRefreshToken;
        if ($refreshToken !== null && (! is_string($refreshToken) || $refreshToken === '')) {
            throw new OAuthFailure('A resposta de autorização Gmail não contém refresh token válido.');
        }

        return new GmailOAuthGrant(
            accessToken: new AccessToken(
                secret: new OAuthSecret($tokens['access_token']),
                expiresAt: (new DateTimeImmutable('@'.(time() + $expiresIn)))->setTimezone(new DateTimeZone('UTC')),
            ),
            refreshToken: $refreshToken === null ? null : new OAuthSecret($refreshToken),
            grantedScopes: $scopes,
        );
    }

    private function validRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($scheme === 'https' && $host !== '')
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
