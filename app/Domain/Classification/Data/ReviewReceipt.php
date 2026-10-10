<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\ReviewScope;

final readonly class ReviewReceipt
{
    public function __construct(
        public int $reviewId,
        public ReviewScope $scope,
        public ?int $officialClassificationId,
        public bool $idempotentReplay,
    ) {}
}
