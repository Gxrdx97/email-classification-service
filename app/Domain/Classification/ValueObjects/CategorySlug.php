<?php

declare(strict_types=1);

namespace App\Domain\Classification\ValueObjects;

use App\Domain\Classification\Exceptions\InvalidCategorySlug;
use Stringable;

final readonly class CategorySlug implements Stringable
{
    private const RESERVED = [
        'pending',
        'categorized',
        'uncategorized',
        'inconclusive',
        'failed',
    ];

    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidCategorySlug('Slug de Categoria inválido.');
        }

        if (in_array($value, self::RESERVED, true)) {
            throw new InvalidCategorySlug('Slug reservado para Estado operacional.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
