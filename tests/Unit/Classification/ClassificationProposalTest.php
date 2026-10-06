<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationContext;
use App\Domain\Classification\Data\ClassificationFailure;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;
use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\OperationalState;
use App\Domain\Classification\Enums\ProposalOutcome;
use App\Domain\Classification\Exceptions\ClassifierProviderFailure;
use App\Domain\Classification\Exceptions\InvalidClassificationProposal;
use App\Domain\Classification\Services\ClassificationProposalValidator;
use App\Domain\Classification\Services\ClassificationService;
use App\Domain\Classification\ValueObjects\CategorySlug;
use Tests\Unit\Classification\Support\FakeClassifierProvider;
use Tests\Unit\Classification\Support\InMemoryCategoryRepository;
use Tests\Unit\Classification\Support\InMemoryOperationalStateRepository;
use Tests\Unit\Classification\Support\PassThroughClassificationGate;
use Tests\Unit\Classification\Support\StaticEvidenceVerifier;

require_once __DIR__.'/Support/TestDoubles.php';

$makeClassificationService = static function (array $output, ?ClassifierProviderFailure $failure = null): array {
    $activeVersion = new CategoryVersion(21, 1, 3, 'Financeiro', CategorySlug::from('finance'), null, null, false);
    $archivedVersion = new CategoryVersion(22, 2, 1, 'Antiga', CategorySlug::from('old-topic'), null, null, false);
    $categories = new InMemoryCategoryRepository(
        new Category(1, CategorySlug::from('finance'), null, $activeVersion),
        new Category(2, CategorySlug::from('old-topic'), new DateTimeImmutable, $archivedVersion),
    );
    $gate = new PassThroughClassificationGate(static fn (string $payload): string => 'sanitized:'.$payload);
    $provider = new FakeClassifierProvider($output, 'rules', 'rules-v3', $failure);
    $operationalState = new InMemoryOperationalStateRepository;
    $operationalState->states[90] = OperationalState::Categorized;
    $operationalState->officialClassificationIds[90] = 321;
    $validator = new ClassificationProposalValidator(new StaticEvidenceVerifier(['local:message:4']));
    $service = new ClassificationService($categories, $provider, $gate, $operationalState, $validator);

    return [$service, $provider, $gate, $operationalState];
};

$validProposalOutput = static fn (array $overrides = []): array => array_replace([
    'categories' => [['category_id' => 1, 'probability' => 0.82]],
    'confidence' => 0.91,
    'review_required' => false,
    'reason_codes' => [],
    'evidence_refs' => ['local:message:4', 'provider:invented-reference'],
    'none_applies' => false,
    'structural_conflict' => false,
], $overrides);

describe('proposta consultiva de classificação', function () use ($makeClassificationService, $validProposalOutput) {
    it('valida Categoria ativa e Evidência local, e sempre exige Revisão humana', function () use ($makeClassificationService, $validProposalOutput) {
        [$service, $provider, $gate] = $makeClassificationService($validProposalOutput());

        $proposal = $service->propose(new ClassificationContext(90, 'texto enriquecido'));

        expect($proposal->outcome)->toBe(ProposalOutcome::Categorized)
            ->and($proposal->categories[0]->version->id)->toBe(21)
            ->and($proposal->categories[0]->probability)->toBe(0.82)
            ->and($proposal->reviewRequired)->toBeTrue()
            ->and($proposal->evidenceRefs)->toBe(['local:message:4'])
            ->and($proposal->providerName)->toBe('rules')
            ->and($proposal->providerModel)->toBe('rules-v3')
            ->and($gate->calls)->toBe(1)
            ->and($gate->activeCategoryIds)->toBe([1])
            ->and($provider->lastInput?->payload)->toBe('sanitized:texto enriquecido');
    });

    it('propõe uncategorized quando a ausência explícita atinge o limiar de 0.60', function () use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'categories' => [],
            'confidence' => 0.60,
            'none_applies' => true,
        ]));

        $proposal = $service->propose(new ClassificationContext(90, 'texto enriquecido'));

        expect($proposal->outcome)->toBe(ProposalOutcome::Uncategorized)
            ->and($proposal->categories)->toBeEmpty()
            ->and($proposal->confidence)->toBe(0.60)
            ->and($proposal->reviewRequired)->toBeTrue();
    });

    it('propõe inconclusive com low_confidence quando nenhum candidato alcança o limiar', function () use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'categories' => [['category_id' => 1, 'probability' => 0.59]],
            'confidence' => 0.59,
        ]));

        $proposal = $service->propose(new ClassificationContext(90, 'texto enriquecido'));

        expect($proposal->outcome)->toBe(ProposalOutcome::Inconclusive)
            ->and($proposal->inconclusiveReason)->toBe(InconclusiveReason::LowConfidence)
            ->and($proposal->categories[0]->version->id)->toBe(21);
    });

    it('apresenta conflito estrutural somente como proposta inconclusiva', function () use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'structural_conflict' => true,
            'inconclusive_reason' => 'conflict',
        ]));

        $proposal = $service->propose(new ClassificationContext(90, 'texto enriquecido'));

        expect($proposal->outcome)->toBe(ProposalOutcome::Inconclusive)
            ->and($proposal->inconclusiveReason)->toBe(InconclusiveReason::Conflict)
            ->and($proposal->categories[0]->version->id)->toBe(21);
    });

    it('rejeita IDs desconhecidos e Categorias arquivadas', function (int $categoryId) use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'categories' => [['category_id' => $categoryId, 'probability' => 0.99]],
        ]));

        expect(fn () => $service->propose(new ClassificationContext(90, 'texto enriquecido')))
            ->toThrow(InvalidClassificationProposal::class);
    })->with([404, 2]);

    it('rejeita probabilidades fora de 0..1 e saída malformada', function (mixed $probability) use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'categories' => [['category_id' => 1, 'probability' => $probability]],
        ]));

        expect(fn () => $service->propose(new ClassificationContext(90, 'texto enriquecido')))
            ->toThrow(InvalidClassificationProposal::class);
    })->with([-0.01, 1.01, '0.8', INF]);

    it('não aceita instruções de autoridade ou campos não declarados vindos do modelo', function () use ($makeClassificationService, $validProposalOutput) {
        [$service] = $makeClassificationService($validProposalOutput([
            'decision_author' => 'human_review',
            'action' => 'confirm',
        ]));

        expect(fn () => $service->propose(new ClassificationContext(90, 'texto enriquecido')))
            ->toThrow(InvalidClassificationProposal::class);
    });

    it('registra falha apenas no Estado operacional e preserva a Classificação oficial anterior', function () use ($makeClassificationService, $validProposalOutput) {
        [$service, , , $operationalState] = $makeClassificationService(
            $validProposalOutput(),
            new ClassifierProviderFailure('provider_timeout'),
        );

        $failure = $service->propose(new ClassificationContext(90, 'texto enriquecido'));

        expect($failure)->toBeInstanceOf(ClassificationFailure::class)
            ->and($failure->failureCode)->toBe('provider_timeout')
            ->and($operationalState->states[90])->toBe(OperationalState::Failed)
            ->and($operationalState->officialClassificationIds[90])->toBe(321);
    });
});
