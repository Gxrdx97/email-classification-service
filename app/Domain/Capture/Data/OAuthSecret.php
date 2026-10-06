<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use JsonSerializable;

final readonly class OAuthSecret implements JsonSerializable
{
    public function __construct(private string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('O segredo OAuth não pode ser vazio.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /** @return array{value: string} */
    public function jsonSerialize(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
