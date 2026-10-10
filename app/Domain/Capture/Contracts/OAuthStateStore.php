<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

use DateTimeImmutable;

interface OAuthStateStore
{
    /** Persiste somente o hash do state até expiresAt. */
    public function issue(string $stateHash, DateTimeImmutable $expiresAt): void;

    /** Consome atomicamente um state não expirado; um replay retorna false. */
    public function consume(string $stateHash): bool;
}
