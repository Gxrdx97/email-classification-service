<?php

declare(strict_types=1);

namespace App\Domain\Classification\Entities;

use App\Domain\Classification\ValueObjects\CategorySlug;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Category
{
    public function __construct(
        public int $id,
        public CategorySlug $slug,
        public ?DateTimeImmutable $archivedAt,
        public CategoryVersion $currentVersion,
    ) {
        if ($id < 1
            || $currentVersion->categoryId !== $id
            || $currentVersion->slug->value !== $slug->value) {
            throw new InvalidArgumentException('Categoria e versão atual incompatíveis.');
        }
    }

    public function isActive(): bool
    {
        return $this->archivedAt === null;
    }
}
