<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class RetryablePreAnalysisException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $this->errorCode) !== 1) {
            throw new InvalidArgumentException('Retry error codes must be sanitized identifiers.');
        }

        parent::__construct('A temporary pre-analysis failure will be retried.');
    }
}
