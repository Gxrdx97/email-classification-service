<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

final readonly class ClassificationFailure
{
    public function __construct(public int $threadId, public string $failureCode) {}
}
