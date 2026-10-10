<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

interface EvidenceReferenceVerifier
{
    public function isVerifiedLocalReference(int $threadId, string $reference): bool;
}
