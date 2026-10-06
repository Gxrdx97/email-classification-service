<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use JsonSerializable;

final readonly class Participant implements JsonSerializable
{
    public function __construct(
        public string $normalizedEmail,
        public ?string $displayName,
        public string $role,
    ) {}

    /** @return array{email: string, display_name: string|null, role: string} */
    public function jsonSerialize(): array
    {
        return ['email' => '[redacted]', 'display_name' => '[redacted]', 'role' => $this->role];
    }

    /** @return array{email: string, display_name: string|null, role: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
