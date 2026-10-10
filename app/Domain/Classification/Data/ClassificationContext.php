<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use InvalidArgumentException;

final readonly class ClassificationContext
{
    public function __construct(public int $threadId, public string $enrichedThread)
    {
        if ($threadId < 1) {
            throw new InvalidArgumentException('Thread inválida.');
        }
    }
}
