<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Data\ThreadCaptureEffect;

final class ThreadCapturePolicy
{
    public function onNewMessage(?int $latestOfficialClassificationId): ThreadCaptureEffect
    {
        return new ThreadCaptureEffect(
            operationalState: 'pending',
            latestOfficialClassificationId: $latestOfficialClassificationId,
            preserveInheritedLabels: true,
            startAutomaticReclassification: false,
        );
    }
}
