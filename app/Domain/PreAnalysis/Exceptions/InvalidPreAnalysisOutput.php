<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Exceptions;

use RuntimeException;

final class InvalidPreAnalysisOutput extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider returned an invalid structured proposal.');
    }
}
