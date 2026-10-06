<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\ReviewDecision;
use App\Domain\Classification\Data\ReviewReceipt;

interface ClassificationReviewRepository
{
    /**
     * Derives current Category versions and validates them in the same transaction as the Review.
     * Persists the Review and any official result/Message override atomically.
     */
    public function record(ReviewDecision $decision): ReviewReceipt;
}
