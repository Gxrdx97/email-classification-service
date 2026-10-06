<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\CategoryRepository;
use App\Domain\Classification\Data\CategoryDefinition;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\CategoryVersionNotFound;
use App\Domain\Classification\ValueObjects\CategorySlug;

final readonly class CategoryService
{
    public function __construct(private CategoryRepository $categories) {}

    public function create(
        string $slug,
        string $name,
        ?string $description = null,
        ?string $classificationInstructions = null,
        bool $examplesEnabled = false,
    ): Category {
        return $this->categories->create(new CategoryDefinition(
            CategorySlug::from($slug),
            $name,
            $description,
            $classificationInstructions,
            $examplesEnabled,
        ));
    }

    public function revise(
        int $categoryId,
        string $slug,
        string $name,
        ?string $description = null,
        ?string $classificationInstructions = null,
        bool $examplesEnabled = false,
    ): Category {
        return $this->categories->revise($categoryId, new CategoryDefinition(
            CategorySlug::from($slug),
            $name,
            $description,
            $classificationInstructions,
            $examplesEnabled,
        ));
    }

    public function archive(int $categoryId): Category
    {
        return $this->categories->archive($categoryId);
    }

    public function version(int $categoryVersionId): CategoryVersion
    {
        return $this->categories->findVersion($categoryVersionId)
            ?? throw new CategoryVersionNotFound('Versão de Categoria não encontrada.');
    }

    /** @return list<Category> */
    public function active(): array
    {
        return $this->categories->listActive();
    }

    /** @param list<int> $categoryIds
     * @return list<CategoryVersion>
     */
    public function activeVersionsFor(array $categoryIds): array
    {
        if (! array_is_list($categoryIds)) {
            throw new CategoryNotActive('A seleção de Categorias deve ser uma lista.');
        }

        foreach ($categoryIds as $categoryId) {
            if (! is_int($categoryId) || $categoryId < 1) {
                throw new CategoryNotActive('A seleção contém ID de Categoria inválido.');
            }
        }

        if (count($categoryIds) !== count(array_unique($categoryIds))) {
            throw new CategoryNotActive('A seleção contém Categorias duplicadas.');
        }

        $versions = $this->categories->findActiveVersionsByCategoryIds($categoryIds);

        if (count($versions) !== count($categoryIds)) {
            throw new CategoryNotActive('A seleção contém Categoria desconhecida ou arquivada.');
        }

        return $versions;
    }
}
