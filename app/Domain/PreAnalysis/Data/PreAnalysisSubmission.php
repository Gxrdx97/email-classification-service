<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

final readonly class PreAnalysisSubmission
{
    /**
     * @param  array<string, mixed>  $payload  Already permitted by the Gate.
     * @param  list<int>  $allowedCategoryVersionIds
     */
    public function __construct(
        public PreAnalysisIdentity $identity,
        public string $questionVersion,
        public string $schemaVersion,
        public array $allowedCategoryVersionIds,
        public array $payload,
    ) {}
}
