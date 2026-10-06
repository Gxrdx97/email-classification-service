<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use InvalidArgumentException;

final readonly class GmailLabelDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $custom,
    ) {
        if ($id === '' || $name === '') {
            throw new InvalidArgumentException('Gmail Label definition is incomplete.');
        }
    }
}
