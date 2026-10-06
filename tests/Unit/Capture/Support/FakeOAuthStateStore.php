<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\OAuthStateStore;
use DateTimeImmutable;

final class FakeOAuthStateStore implements OAuthStateStore
{
    /** @var array<string, DateTimeImmutable> */
    public array $states = [];

    public function issue(string $stateHash, DateTimeImmutable $expiresAt): void
    {
        $this->states[$stateHash] = $expiresAt;
    }

    public function consume(string $stateHash): bool
    {
        $expiresAt = $this->states[$stateHash] ?? null;
        unset($this->states[$stateHash]);

        return $expiresAt !== null && $expiresAt > new DateTimeImmutable;
    }
}
