<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Contracts\GmailOAuthProvider;
use App\Domain\Capture\Contracts\OAuthConnectionStore;
use App\Domain\Capture\Contracts\OAuthStateStore;
use App\Domain\Capture\Contracts\OAuthTokenCipher;
use App\Domain\Capture\Data\AccessToken;
use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Data\OAuthConnection;
use App\Domain\Capture\Data\OAuthConnectionSummary;
use App\Domain\Capture\Exceptions\OAuthAccountMismatch;
use App\Domain\Capture\Exceptions\OAuthRefreshUnavailable;
use App\Domain\Capture\Exceptions\OAuthStateRejected;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final readonly class GmailOAuthService
{
    public function __construct(
        private GmailOAuthProvider $provider,
        private OAuthStateStore $states,
        private OAuthConnectionStore $connections,
        private OAuthTokenCipher $cipher,
    ) {}

    public function begin(): string
    {
        $state = bin2hex(random_bytes(32));
        $expiresAt = $this->now()->add(new DateInterval('PT10M'));
        $this->states->issue(hash('sha256', $state), $expiresAt);

        return $this->provider->authorizationUrl($state);
    }

    public function complete(string $state, string $authorizationCode): OAuthConnectionSummary
    {
        if (
            ! preg_match('/^[a-f0-9]{64}$/i', $state)
            || trim($authorizationCode) === ''
            || strlen($authorizationCode) > 4096
        ) {
            throw new OAuthStateRejected('A resposta OAuth do Gmail é inválida.');
        }

        if (! $this->states->consume(hash('sha256', $state))) {
            throw new OAuthStateRejected('O state OAuth expirou ou já foi utilizado.');
        }

        $grant = $this->provider->exchangeCode($authorizationCode);
        GmailScopes::assertExact($grant->grantedScopes);

        $profile = $this->provider->profile($grant->accessToken->reveal());
        $existing = $this->connections->get();

        if ($existing !== null && strcasecmp($existing->emailAddress, $profile->emailAddress) !== 0) {
            throw new OAuthAccountMismatch('Desconecte a conta Gmail atual antes de conectar outra.');
        }

        $newRefreshToken = $grant->refreshToken();
        if ($existing !== null) {
            GmailScopes::assertExact($existing->grantedScopes);
        }

        if ($newRefreshToken === null) {
            if ($existing === null) {
                throw new OAuthRefreshUnavailable('O Gmail não forneceu um refresh token para acesso offline.');
            }

            $ciphertext = $existing->refreshTokenCiphertext();
        } else {
            $ciphertext = $this->cipher->encrypt($newRefreshToken->reveal());
        }

        $connection = new OAuthConnection(
            gmailUserId: $profile->emailAddress,
            emailAddress: $profile->emailAddress,
            grantedScopes: GmailScopes::required(),
            refreshTokenCiphertext: $ciphertext,
            connectedAt: $this->now(),
        );
        $this->connections->save($connection);

        return new OAuthConnectionSummary(
            emailAddress: $connection->emailAddress,
            grantedScopes: $connection->grantedScopes,
            connectedAt: $connection->connectedAt,
        );
    }

    public function refreshAccessToken(): AccessToken
    {
        $connection = $this->connections->get();
        if ($connection === null) {
            throw new OAuthRefreshUnavailable('A conta Gmail não está conectada.');
        }

        GmailScopes::assertExact($connection->grantedScopes);

        try {
            $refreshToken = $this->cipher->decrypt($connection->refreshTokenCiphertext());
        } catch (\Throwable) {
            throw new OAuthRefreshUnavailable('Não foi possível recuperar a credencial Gmail cifrada.');
        }

        $grant = $this->provider->refresh($refreshToken);
        if ($grant->grantedScopes !== []) {
            GmailScopes::assertExact($grant->grantedScopes);
        }

        $rotatedToken = $grant->refreshToken();
        if ($rotatedToken !== null && $rotatedToken->reveal() !== $refreshToken) {
            $this->connections->save(
                $connection->withRefreshTokenCiphertext($this->cipher->encrypt($rotatedToken->reveal())),
            );
        }

        return $grant->accessToken;
    }

    public function revoke(): void
    {
        $connection = $this->connections->get();
        if ($connection === null) {
            return;
        }

        GmailScopes::assertExact($connection->grantedScopes);

        try {
            $refreshToken = $this->cipher->decrypt($connection->refreshTokenCiphertext());
        } catch (\Throwable) {
            throw new OAuthRefreshUnavailable('Não foi possível recuperar a credencial Gmail cifrada.');
        }

        $this->provider->revoke($refreshToken);
        $this->connections->delete();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
