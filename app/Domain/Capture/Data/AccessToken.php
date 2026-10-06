<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use DateTimeImmutable;
use JsonSerializable;

final readonly class AccessToken implements JsonSerializable
{
    public function __construct(
        private OAuthSecret $secret,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function reveal(): string
    {
        return $this->secret->reveal();
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** @return array{token: string, expires_at: string} */
    public function jsonSerialize(): array
    {
        return ['token' => '[redacted]', 'expires_at' => $this->expiresAt->format(DATE_ATOM)];
    }

    /** @return array{token: string, expires_at: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
