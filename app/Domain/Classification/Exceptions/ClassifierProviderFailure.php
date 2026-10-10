<?php

declare(strict_types=1);

namespace App\Domain\Classification\Exceptions;

use RuntimeException;

final class ClassifierProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $failureCode) !== 1) {
            throw new RuntimeException('Provider failure code must be a sanitized code.');
        }

        parent::__construct($failureCode);
    }
}
