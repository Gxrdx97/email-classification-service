<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use DateTimeImmutable;
use JsonSerializable;

final readonly class OAuthConnection implements JsonSerializable
{
    /**
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public string $gmailUserId,
        public string $emailAddress,
        public array $grantedScopes,
        private string $refreshTokenCiphertext,
        public DateTimeImmutable $connectedAt,
    ) {}

    public function refreshTokenCiphertext(): string
    {
        return $this->refreshTokenCiphertext;
    }

    public function withRefreshTokenCiphertext(string $ciphertext): self
    {
        return new self(
            $this->gmailUserId,
            $this->emailAddress,
            $this->grantedScopes,
            $ciphertext,
            $this->connectedAt,
        );
    }

    /** @return array{gmail_user_id: string, email_address: string, granted_scopes: list<string>, refresh_token: string, connected_at: string} */
    public function jsonSerialize(): array
    {
        return [
            'gmail_user_id' => '[redacted]',
            'email_address' => '[redacted]',
            'granted_scopes' => $this->grantedScopes,
            'refresh_token' => '[redacted]',
            'connected_at' => $this->connectedAt->format(DATE_ATOM),
        ];
    }

    /** @return array{gmail_user_id: string, email_address: string, granted_scopes: list<string>, refresh_token: string, connected_at: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
