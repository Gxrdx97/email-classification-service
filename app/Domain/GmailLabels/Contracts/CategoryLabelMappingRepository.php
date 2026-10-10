<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Contracts;

use App\Domain\GmailLabels\Data\CategoryLabelMapping;

interface CategoryLabelMappingRepository
{
    public function find(int $categoryId): ?CategoryLabelMapping;

    public function displayName(int $categoryId): ?string;

    public function saveIfMissing(int $categoryId, string $displayName, string $providerLabelId): CategoryLabelMapping;

    /** @return list<CategoryLabelMapping> */
    public function allManaged(): array;
}
