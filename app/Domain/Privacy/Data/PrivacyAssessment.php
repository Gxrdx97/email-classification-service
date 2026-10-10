<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Data;

use App\Domain\Privacy\Enums\PrivacyOutcome;

final readonly class PrivacyAssessment
{
    /**
     * @param  list<string>  $findingCodes
     * @param  array<string, mixed>|null  $permittedPayload
     */
    public function __construct(
        public PrivacyOutcome $outcome,
        public array $findingCodes,
        public ?array $permittedPayload,
    ) {}
}
