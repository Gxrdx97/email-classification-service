<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use DateTimeImmutable;
use JsonSerializable;

final readonly class GmailOAuthGrant implements JsonSerializable
{
    /**
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public AccessToken $accessToken,
        private ?OAuthSecret $refreshToken,
        public array $grantedScopes,
    ) {}

    public function refreshToken(): ?OAuthSecret
    {
        return $this->refreshToken;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->accessToken->expiresAt;
    }

    /** @return array{access_token: string, refresh_token: string, granted_scopes: list<string>} */
    public function jsonSerialize(): array
    {
        return [
            'access_token' => '[redacted]',
            'refresh_token' => '[redacted]',
            'granted_scopes' => $this->grantedScopes,
        ];
    }

    /** @return array{access_token: string, refresh_token: string, granted_scopes: list<string>} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
