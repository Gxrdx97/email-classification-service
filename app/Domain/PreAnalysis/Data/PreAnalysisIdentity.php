<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

use InvalidArgumentException;

final readonly class PreAnalysisIdentity
{
    public function __construct(
        public int $accountId,
        public int $threadId,
        public string $threadSnapshotHash,
        public string $categoryCatalogVersion,
        public string $providerModel,
        public string $privacyPolicyVersion,
    ) {
        if ($this->accountId < 1 || $this->threadId < 1) {
            throw new InvalidArgumentException('Pre-analysis identity IDs must be positive.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $this->threadSnapshotHash) !== 1) {
            throw new InvalidArgumentException('A valid thread snapshot hash is required.');
        }

        foreach ([$this->categoryCatalogVersion, $this->providerModel, $this->privacyPolicyVersion] as $version) {
            if (trim($version) === '') {
                throw new InvalidArgumentException('Pre-analysis identity versions are required.');
            }
        }
    }
}
