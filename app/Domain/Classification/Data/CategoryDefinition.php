<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\ValueObjects\CategorySlug;
use InvalidArgumentException;

final readonly class CategoryDefinition
{
    public string $name;

    public ?string $description;

    public ?string $classificationInstructions;

    public function __construct(
        public CategorySlug $slug,
        string $name,
        ?string $description = null,
        ?string $classificationInstructions = null,
        public bool $examplesEnabled = false,
    ) {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new InvalidArgumentException('Nome da Categoria é obrigatório.');
        }

        $this->name = $normalizedName;
        $this->description = self::normalizeOptionalText($description);
        $this->classificationInstructions = self::normalizeOptionalText($classificationInstructions);
    }

    private static function normalizeOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
