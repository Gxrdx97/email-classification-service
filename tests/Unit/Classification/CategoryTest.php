<?php

declare(strict_types=1);

use App\Domain\Classification\Data\CategoryDefinition;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\InvalidCategorySlug;
use App\Domain\Classification\Services\CategoryService;
use App\Domain\Classification\ValueObjects\CategorySlug;
use Tests\Unit\Classification\Support\InMemoryCategoryRepository;

require_once __DIR__.'/Support/TestDoubles.php';

describe('Categoria versionada', function () {
    it('rejeita todos os slugs reservados para Estados operacionais', function (string $slug) {
        expect(fn () => CategorySlug::from($slug))->toThrow(InvalidCategorySlug::class);
    })->with(['pending', 'categorized', 'uncategorized', 'inconclusive', 'failed']);

    it('mantém versões anteriores endereçáveis e exclui Categorias arquivadas da allowlist ativa', function () {
        $firstVersion = new CategoryVersion(
            11,
            7,
            1,
            'Financeiro',
            CategorySlug::from('finance'),
            'Contas e pagamentos',
            null,
            false,
        );
        $archivedVersion = new CategoryVersion(
            12,
            8,
            1,
            'Arquivada',
            CategorySlug::from('old-topic'),
            null,
            null,
            false,
        );
        $repository = new InMemoryCategoryRepository(
            new Category(7, CategorySlug::from('finance'), null, $firstVersion),
            new Category(8, CategorySlug::from('old-topic'), new DateTimeImmutable, $archivedVersion),
        );
        $service = new CategoryService($repository);

        $revised = $service->revise(7, 'finance', 'Financeiro e orçamento', 'Definição atualizada');

        expect($service->version(11)->name)->toBe('Financeiro')
            ->and($revised->currentVersion->versionNumber)->toBe(2)
            ->and(array_map(static fn (Category $category): int => $category->id, $service->active()))->toBe([7])
            ->and($service->activeVersionsFor([7])[0]->versionNumber)->toBe(2)
            ->and(fn () => $service->activeVersionsFor([8]))
            ->toThrow(CategoryNotActive::class);
    });

    it('aplica a mesma validação de slug ao criar uma Categoria', function () {
        $service = new CategoryService(new InMemoryCategoryRepository);

        expect(fn () => $service->create('failed', 'Falhas'))
            ->toThrow(InvalidCategorySlug::class);
    });

    it('normaliza a definição sem alterar a versão já persistida', function () {
        $definition = new CategoryDefinition(CategorySlug::from('receipts'), '  Recibos  ', '  Pagamentos  ');

        expect($definition->name)->toBe('Recibos')
            ->and($definition->description)->toBe('Pagamentos');
    });
});
