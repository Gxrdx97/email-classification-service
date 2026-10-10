<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Entities\Category;
use InvalidArgumentException;

final readonly class GatedClassificationInput
{
    /** @param list<Category> $activeCategories */
    public function __construct(
        public int $threadId,
        public string $payload,
        public array $activeCategories,
    ) {
        if ($threadId < 1) {
            throw new InvalidArgumentException('Thread inválida.');
        }

        foreach ($activeCategories as $category) {
            if (! $category instanceof Category || ! $category->isActive()) {
                throw new InvalidArgumentException('Entrada contém Categoria não ativa.');
            }
        }
    }
}
