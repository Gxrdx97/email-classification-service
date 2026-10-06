<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ReviewRequest;
use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\InvalidReviewDecision;
use App\Domain\Classification\Services\ClassificationReviewService;
use Tests\Unit\Classification\Support\RecordingReviewRepository;
use Tests\Unit\Classification\Support\StaticEvidenceVerifier;
use Tests\Unit\Classification\Support\StaticReviewActorProvider;

require_once __DIR__.'/Support/TestDoubles.php';

$makeReviewService = static function (): array {
    $repository = new RecordingReviewRepository;
    $service = new ClassificationReviewService(
        $repository,
        new StaticReviewActorProvider,
        new StaticEvidenceVerifier(['local:body:1']),
    );

    return [$service, $repository];
};

describe('Revisão humana', function () use ($makeReviewService) {
    it('registra a escolha explícita por Categorias e preserva proveniência humana', function () use ($makeReviewService) {
        [$service, $repository] = $makeReviewService();

        $receipt = $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::ConfirmSuggestion,
            idempotencyKey: 'review-confirm-1',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [2, 1],
            confidence: 0.94,
            evidenceRefs: ['local:body:1'],
        ));

        expect($receipt->scope)->toBe(ReviewScope::Thread)
            ->and($receipt->officialClassificationId)->toBe($receipt->reviewId)
            ->and($repository->decisions[0]->categoryIds)->toBe([1, 2])
            ->and($repository->decisions[0]->action)->toBe(ReviewAction::ConfirmSuggestion)
            ->and($repository->decisions[0]->reviewerSubject)->toBe('user@example.test');
    });

    it('rejeita Categoria desconhecida ou arquivada na Revisão, independentemente da proposta', function (int $categoryId) use ($makeReviewService) {
        [$service] = $makeReviewService();

        expect(fn () => $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-invalid-'.$categoryId,
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$categoryId],
        )))->toThrow(CategoryNotActive::class);
    })->with([404, 3]);

    it('grava a rejeição sem desfecho e sem Classificação oficial', function () use ($makeReviewService) {
        [$service, $repository] = $makeReviewService();

        $receipt = $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::RejectSuggestion,
            idempotencyKey: 'review-reject-1',
        ));

        expect($receipt->officialClassificationId)->toBeNull()
            ->and($repository->decisions[0]->outcome)->toBeNull()
            ->and($repository->decisions[0]->action)->toBe(ReviewAction::RejectSuggestion);
    });

    it('torna a repetição da mesma chave idempotente e preserva a decisão original', function () use ($makeReviewService) {
        [$service, $repository] = $makeReviewService();
        $request = new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-idempotent-1',
            outcome: OfficialOutcome::Uncategorized,
        );

        $first = $service->submit($request);
        $replay = $service->submit($request);

        expect($replay->idempotentReplay)->toBeTrue()
            ->and($replay->reviewId)->toBe($first->reviewId)
            ->and($replay->officialClassificationId)->toBe($first->officialClassificationId)
            ->and($repository->decisions)->toHaveCount(1);
    });

    it('registra correção restrita à Message como override sem decisão oficial da Thread', function () use ($makeReviewService) {
        [$service, $repository] = $makeReviewService();

        $receipt = $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Message,
            targetMessageId: 900,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-message-1',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [2],
        ));

        expect($receipt->scope)->toBe(ReviewScope::Message)
            ->and($receipt->officialClassificationId)->toBeNull()
            ->and($repository->decisions[0]->targetMessageId)->toBe(900)
            ->and($repository->decisions[0]->categoryIds)->toBe([2]);
    });

    it('exige motivo para inconclusive e não modela failed como decisão classificatória', function () use ($makeReviewService) {
        [$service] = $makeReviewService();

        expect(fn () => $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-inconclusive-without-reason',
            outcome: OfficialOutcome::Inconclusive,
        )))->toThrow(InvalidReviewDecision::class);

        expect(OfficialOutcome::tryFrom('failed'))->toBeNull()
            ->and(InconclusiveReason::Conflict->value)->toBe('conflict');
    });

    it('rejeita Evidência que não tenha proveniência local verificada', function () use ($makeReviewService) {
        [$service] = $makeReviewService();

        expect(fn () => $service->submit(new ReviewRequest(
            threadId: 90,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-unverified-evidence',
            outcome: OfficialOutcome::Uncategorized,
            evidenceRefs: ['provider:invented'],
        )))->toThrow(InvalidReviewDecision::class);
    });
});
