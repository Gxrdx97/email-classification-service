<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Contracts;

use App\Domain\GmailLabels\Data\CategoryLabelMapping;

interface CategoryLabelGateway
{
    public function ensureCategoryLabel(int $categoryId, string $displayName): CategoryLabelMapping;

    /** @param list<string> $desiredManagedLabelIds */
    public function reconcileMessageAssociations(string $gmailMessageId, array $desiredManagedLabelIds): void;
}
