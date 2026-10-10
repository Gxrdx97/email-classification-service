<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

use App\Domain\PreAnalysis\Data\PreAnalysisOutboxMessage;

interface PreAnalysisQueuePublisher
{
    public function publish(PreAnalysisOutboxMessage $message): void;
}
