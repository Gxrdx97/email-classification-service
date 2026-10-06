<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use DateTimeImmutable;
use JsonSerializable;

final readonly class OAuthConnectionSummary implements JsonSerializable
{
    /**
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public string $emailAddress,
        public array $grantedScopes,
        public DateTimeImmutable $connectedAt,
    ) {}

    /** @return array{email_address: string, granted_scopes: list<string>, connected_at: string} */
    public function jsonSerialize(): array
    {
        return [
            'email_address' => '[redacted]',
            'granted_scopes' => $this->grantedScopes,
            'connected_at' => $this->connectedAt->format(DATE_ATOM),
        ];
    }

    /** @return array{email_address: string, granted_scopes: list<string>, connected_at: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
