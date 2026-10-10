<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\ClassificationReviewRepository;
use App\Domain\Classification\Contracts\EvidenceReferenceVerifier;
use App\Domain\Classification\Contracts\ReviewActorProvider;
use App\Domain\Classification\Data\ReviewDecision;
use App\Domain\Classification\Data\ReviewReceipt;
use App\Domain\Classification\Data\ReviewRequest;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;
use App\Domain\Classification\Exceptions\InvalidReviewDecision;

final readonly class ClassificationReviewService
{
    public function __construct(
        private ClassificationReviewRepository $reviews,
        private ReviewActorProvider $actorProvider,
        private EvidenceReferenceVerifier $evidence,
    ) {}

    public function submit(ReviewRequest $request): ReviewReceipt
    {
        $this->validateTarget($request);
        $this->validateDecision($request);

        $categoryIds = $request->categoryIds;
        sort($categoryIds);

        $evidenceRefs = $this->verifiedEvidenceRefs($request);
        $actor = $this->actorProvider->currentActor();

        return $this->reviews->record(new ReviewDecision(
            $request->threadId,
            $request->scope,
            $request->targetMessageId,
            $request->action,
            $actor->subject,
            trim($request->idempotencyKey),
            $request->outcome,
            $categoryIds,
            $request->confidence,
            $request->inconclusiveReason,
            $evidenceRefs,
        ));
    }

    private function validateTarget(ReviewRequest $request): void
    {
        if ($request->threadId < 1
            || trim($request->idempotencyKey) === ''
            || ($request->scope === ReviewScope::Thread && $request->targetMessageId !== null)
            || ($request->scope === ReviewScope::Message
                && ($request->targetMessageId === null || $request->targetMessageId < 1))) {
            throw new InvalidReviewDecision('Alvo ou chave idempotente da Revisão inválidos.');
        }
    }

    private function validateDecision(ReviewRequest $request): void
    {
        if ($request->action === ReviewAction::RejectSuggestion) {
            if ($request->outcome !== null
                || $request->categoryIds !== []
                || $request->evidenceRefs !== []
                || $request->inconclusiveReason !== null
                || $request->confidence !== 1.0) {
                throw new InvalidReviewDecision('Rejeição de Sugestão não escolhe resultado oficial.');
            }

            return;
        }

        if ($request->outcome === null
            || ! is_finite($request->confidence)
            || $request->confidence < 0
            || $request->confidence > 1) {
            throw new InvalidReviewDecision('Decisão humana explícita inválida.');
        }

        if ($request->scope === ReviewScope::Message
            && ($request->action !== ReviewAction::Correct
                || ! in_array($request->outcome, [OfficialOutcome::Categorized, OfficialOutcome::Uncategorized], true))) {
            throw new InvalidReviewDecision('Correção de Message aceita apenas categorized ou uncategorized.');
        }

        if ($request->scope === ReviewScope::Thread
            && ! in_array($request->action, [
                ReviewAction::ConfirmSuggestion,
                ReviewAction::Correct,
                ReviewAction::Manual,
            ], true)) {
            throw new InvalidReviewDecision('Ação não pode criar decisão oficial de Thread.');
        }

        $hasCategories = $request->categoryIds !== [];

        if (($request->outcome === OfficialOutcome::Categorized) !== $hasCategories) {
            throw new InvalidReviewDecision('Somente categorized pode conter Categorias, e exige ao menos uma.');
        }

        if (($request->outcome === OfficialOutcome::Inconclusive)
            !== ($request->inconclusiveReason !== null)) {
            throw new InvalidReviewDecision('inconclusive exige motivo; outros desfechos não aceitam motivo.');
        }

        if (! array_is_list($request->categoryIds) || ! array_is_list($request->evidenceRefs)) {
            throw new InvalidReviewDecision('Categorias e Evidências devem ser listas.');
        }

        foreach ($request->categoryIds as $categoryId) {
            if (! is_int($categoryId) || $categoryId < 1) {
                throw new InvalidReviewDecision('ID de Categoria inválido.');
            }
        }

        if (count($request->categoryIds) !== count(array_unique($request->categoryIds))) {
            throw new InvalidReviewDecision('A decisão contém Categorias duplicadas.');
        }

        if ($request->scope === ReviewScope::Message
            && ($request->evidenceRefs !== [] || $request->confidence !== 1.0)) {
            throw new InvalidReviewDecision('Correção restrita à Message não aceita proveniência de proposta de Thread.');
        }
    }

    /** @return list<string> */
    private function verifiedEvidenceRefs(ReviewRequest $request): array
    {
        if ($request->scope === ReviewScope::Message) {
            return [];
        }

        $references = [];

        foreach ($request->evidenceRefs as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw new InvalidReviewDecision('Referência de Evidência inválida.');
            }

            $reference = trim($reference);

            if (! $this->evidence->isVerifiedLocalReference($request->threadId, $reference)) {
                throw new InvalidReviewDecision('Revisão contém Evidência local não verificada.');
            }

            $references[] = $reference;
        }

        $references = array_values(array_unique($references));
        sort($references);

        return $references;
    }
}
