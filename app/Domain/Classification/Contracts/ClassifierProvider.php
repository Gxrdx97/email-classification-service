<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\GatedClassificationInput;

interface ClassifierProvider
{
    public function name(): string;

    public function model(): ?string;

    /**
     * Returns untrusted structured provider output. Exhausted retries must be reported as
     * ClassifierProviderFailure, with a sanitized failure code and no raw provider payload.
     *
     * @return array{
     *   categories: list<array{category_id: int, probability: int|float}>,
     *   confidence: int|float,
     *   review_required: bool,
     *   reason_codes: list<string>,
     *   evidence_refs: list<string>,
     *   none_applies?: bool,
     *   structural_conflict?: bool,
     *   inconclusive_reason?: string
     * }
     */
    public function propose(GatedClassificationInput $input): array;
}
