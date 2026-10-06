<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

use InvalidArgumentException;

final readonly class PreAnalysisDispatchPolicy
{
    public function __construct(
        public string $provider = 'none',
        public bool $providerEnabled = false,
        public ?string $providerModel = null,
        public int $batchSize = 25,
        public int $leaseSeconds = 60,
        public int $maxDispatchAttempts = 5,
        public int $maxProviderAttempts = 5,
        public int $baseBackoffSeconds = 5,
        public int $maxBackoffSeconds = 300,
    ) {
        if (! in_array($this->provider, ['none', 'jev'], true)
            || $this->batchSize < 1
            || $this->batchSize > 100
            || $this->leaseSeconds < 1
            || $this->leaseSeconds > 3600
            || $this->maxDispatchAttempts < 1
            || $this->maxDispatchAttempts > 20
            || $this->maxProviderAttempts < 1
            || $this->maxProviderAttempts > 20
            || $this->baseBackoffSeconds < 1
            || $this->maxBackoffSeconds < $this->baseBackoffSeconds
            || $this->maxBackoffSeconds > 86400) {
            throw new InvalidArgumentException('Pre-analysis dispatch policy is outside its supported bounds.');
        }
    }

    public function isEnabled(): bool
    {
        return $this->provider === 'jev'
            && $this->providerEnabled
            && is_string($this->providerModel)
            && trim($this->providerModel) !== '';
    }

    public function backoffSeconds(int $attemptCount): int
    {
        $exponent = min(max(0, $attemptCount - 1), 20);
        $delay = $this->baseBackoffSeconds * (2 ** $exponent);

        return min((int) $delay, $this->maxBackoffSeconds);
    }
}
