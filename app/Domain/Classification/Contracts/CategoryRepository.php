<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\CategoryDefinition;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;

interface CategoryRepository
{
    public function create(CategoryDefinition $definition): Category;

    public function revise(int $categoryId, CategoryDefinition $definition): Category;

    public function archive(int $categoryId): Category;

    public function findVersion(int $categoryVersionId): ?CategoryVersion;

    /** @return list<Category> */
    public function listActive(): array;

    /** @param list<int> $categoryIds
     * @return list<CategoryVersion>
     */
    public function findActiveVersionsByCategoryIds(array $categoryIds): array;
}
