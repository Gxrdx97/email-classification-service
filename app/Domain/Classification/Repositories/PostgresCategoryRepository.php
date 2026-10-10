<?php

declare(strict_types=1);

namespace App\Domain\Classification\Repositories;

use App\Domain\Classification\Contracts\CategoryRepository;
use App\Domain\Classification\Data\CategoryDefinition;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\CategoryNotFound;
use App\Domain\Classification\ValueObjects\CategorySlug;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class PostgresCategoryRepository implements CategoryRepository
{
    public function create(CategoryDefinition $definition): Category
    {
        return DB::transaction(function () use ($definition): Category {
            $now = now();
            $categoryId = (int) DB::table('categories')->insertGetId([
                'slug' => $definition->slug->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $version = $this->insertVersion($categoryId, 1, $definition, $now);
            DB::table('categories')->where('id', $categoryId)->update([
                'current_version_id' => $version->id,
                'updated_at' => $now,
            ]);

            return new Category($categoryId, $definition->slug, null, $version);
        });
    }

    public function revise(int $categoryId, CategoryDefinition $definition): Category
    {
        return DB::transaction(function () use ($categoryId, $definition): Category {
            $category = DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();

            if ($category === null) {
                throw new CategoryNotFound('Categoria não encontrada.');
            }

            if ($category->archived_at !== null) {
                throw new CategoryNotActive('Categoria arquivada não pode receber nova versão.');
            }

            $versionNumber = (int) DB::table('category_versions')
                ->where('category_id', $categoryId)
                ->max('version_number') + 1;
            $now = now();
            $version = $this->insertVersion($categoryId, $versionNumber, $definition, $now);

            DB::table('categories')->where('id', $categoryId)->update([
                'slug' => $definition->slug->value,
                'current_version_id' => $version->id,
                'updated_at' => $now,
            ]);

            return new Category($categoryId, $definition->slug, null, $version);
        });
    }

    public function archive(int $categoryId): Category
    {
        return DB::transaction(function () use ($categoryId): Category {
            $category = DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();

            if ($category === null) {
                throw new CategoryNotFound('Categoria não encontrada.');
            }

            if ($category->archived_at === null) {
                $archivedAt = now();
                DB::table('categories')->where('id', $categoryId)->update([
                    'archived_at' => $archivedAt,
                    'updated_at' => $archivedAt,
                ]);
                $category->archived_at = $archivedAt;
            }

            $version = $this->findVersion((int) $category->current_version_id);

            if ($version === null) {
                throw new CategoryNotFound('Versão atual da Categoria não encontrada.');
            }

            return new Category(
                (int) $category->id,
                CategorySlug::from((string) $category->slug),
                new DateTimeImmutable((string) $category->archived_at),
                $version,
            );
        });
    }

    public function findVersion(int $categoryVersionId): ?CategoryVersion
    {
        $row = DB::table('category_versions')->where('id', $categoryVersionId)->first();

        return $row === null ? null : $this->hydrateVersion($row);
    }

    public function listActive(): array
    {
        $rows = DB::table('categories')
            ->join('category_versions as current_version', function ($join): void {
                $join->on('current_version.id', '=', 'categories.current_version_id')
                    ->on('current_version.category_id', '=', 'categories.id');
            })
            ->whereNull('categories.archived_at')
            ->select([
                'categories.id as category_id',
                'categories.slug as category_slug',
                'categories.archived_at',
                'current_version.id as version_id',
                'current_version.category_id as version_category_id',
                'current_version.version_number',
                'current_version.name',
                'current_version.slug as version_slug',
                'current_version.description',
                'current_version.classification_instructions',
                'current_version.examples_enabled',
            ])
            ->orderBy('categories.id')
            ->get();

        return $rows->map(fn (object $row): Category => new Category(
            (int) $row->category_id,
            CategorySlug::from((string) $row->category_slug),
            null,
            $this->hydrateVersion($row, true),
        ))->all();
    }

    public function findActiveVersionsByCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = DB::table('categories')
            ->join('category_versions', function ($join): void {
                $join->on('category_versions.id', '=', 'categories.current_version_id')
                    ->on('category_versions.category_id', '=', 'categories.id');
            })
            ->whereNull('categories.archived_at')
            ->whereIn('categories.id', $categoryIds)
            ->select([
                'category_versions.id',
                'category_versions.category_id',
                'category_versions.version_number',
                'category_versions.name',
                'category_versions.slug',
                'category_versions.description',
                'category_versions.classification_instructions',
                'category_versions.examples_enabled',
            ])
            ->orderBy('category_versions.category_id')
            ->get();

        return $rows->map(fn (object $row): CategoryVersion => $this->hydrateVersion($row))->all();
    }

    private function insertVersion(
        int $categoryId,
        int $versionNumber,
        CategoryDefinition $definition,
        mixed $now,
    ): CategoryVersion {
        $id = (int) DB::table('category_versions')->insertGetId([
            'category_id' => $categoryId,
            'version_number' => $versionNumber,
            'name' => $definition->name,
            'slug' => $definition->slug->value,
            'description' => $definition->description,
            'classification_instructions' => $definition->classificationInstructions,
            'examples_enabled' => $definition->examplesEnabled,
            'created_at' => $now,
        ]);

        return new CategoryVersion(
            $id,
            $categoryId,
            $versionNumber,
            $definition->name,
            $definition->slug,
            $definition->description,
            $definition->classificationInstructions,
            $definition->examplesEnabled,
        );
    }

    private function hydrateVersion(object $row, bool $aliased = false): CategoryVersion
    {
        return new CategoryVersion(
            (int) ($aliased ? $row->version_id : $row->id),
            (int) ($aliased ? $row->version_category_id : $row->category_id),
            (int) $row->version_number,
            (string) $row->name,
            CategorySlug::from((string) ($aliased ? $row->version_slug : $row->slug)),
            $row->description === null ? null : (string) $row->description,
            $row->classification_instructions === null ? null : (string) $row->classification_instructions,
            $this->boolean($row->examples_enabled),
        );
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
