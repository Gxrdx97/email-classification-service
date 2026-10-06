<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\ClassificationContext;
use App\Domain\Classification\Data\GatedClassificationInput;
use App\Domain\Classification\Entities\Category;

interface ClassificationPayloadGate
{
    /** Applies the configured privacy policy before any provider receives thread content. */
    /** @param list<Category> $activeCategories */
    public function prepare(ClassificationContext $context, array $activeCategories): GatedClassificationInput;
}
