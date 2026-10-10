<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels\Exceptions;

use RuntimeException;

final class GmailLabelApiFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gmail Label API request failed.');
    }
}
