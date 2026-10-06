<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Entities\CategoryVersion;
use InvalidArgumentException;

final readonly class ProposedCategory
{
    public function __construct(public CategoryVersion $version, public float $probability)
    {
        if (! is_finite($probability) || $probability < 0 || $probability > 1) {
            throw new InvalidArgumentException('Probabilidade fora do intervalo 0..1.');
        }
    }
}
