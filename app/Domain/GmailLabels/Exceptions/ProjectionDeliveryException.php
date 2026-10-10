<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Exceptions;

use RuntimeException;

final class ProjectionDeliveryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gmail Label projection delivery failed.');
    }
}
