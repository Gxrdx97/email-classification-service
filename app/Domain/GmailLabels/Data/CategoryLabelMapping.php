<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use InvalidArgumentException;

final readonly class CategoryLabelMapping
{
    public function __construct(
        public int $categoryId,
        public string $displayName,
        public string $providerLabelId,
    ) {
        if ($categoryId < 1 || $displayName === '' || $providerLabelId === '') {
            throw new InvalidArgumentException('Category Label mapping values are invalid.');
        }
    }
}
