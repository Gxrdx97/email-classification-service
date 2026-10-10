<?php

declare(strict_types=1);

namespace App\Domain\Classification\Entities;

use App\Domain\Classification\ValueObjects\CategorySlug;
use InvalidArgumentException;

final readonly class CategoryVersion
{
    public function __construct(
        public int $id,
        public int $categoryId,
        public int $versionNumber,
        public string $name,
        public CategorySlug $slug,
        public ?string $description,
        public ?string $classificationInstructions,
        public bool $examplesEnabled,
    ) {
        if ($id < 1 || $categoryId < 1 || $versionNumber < 1 || trim($name) === '') {
            throw new InvalidArgumentException('Snapshot de Categoria inválido.');
        }
    }
}
