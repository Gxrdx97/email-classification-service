<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

final readonly class PreAnalysisSuggestion
{
    public function __construct(
        public int $categoryVersionId,
        public float $probability,
        public ?string $uncertaintyCode,
        public bool $structuralConflict,
    ) {}
}
