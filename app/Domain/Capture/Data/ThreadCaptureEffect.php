<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

final readonly class ThreadCaptureEffect
{
    public function __construct(
        public string $operationalState,
        public ?int $latestOfficialClassificationId,
        public bool $preserveInheritedLabels,
        public bool $startAutomaticReclassification,
    ) {}
}
