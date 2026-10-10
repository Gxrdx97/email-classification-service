<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class PreAnalysisProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $this->errorCode) !== 1) {
            throw new InvalidArgumentException('Provider error codes must be sanitized identifiers.');
        }

        parent::__construct('The pre-analysis provider request failed.');
    }
}
