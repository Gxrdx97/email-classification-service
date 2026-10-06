<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\EvidenceReferenceVerifier;
use App\Domain\Classification\Data\ClassificationProposal;
use App\Domain\Classification\Data\GatedClassificationInput;
use App\Domain\Classification\Data\ProposedCategory;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\ProposalOutcome;
use App\Domain\Classification\Exceptions\InvalidClassificationProposal;

final readonly class ClassificationProposalValidator
{
    private const REQUIRED_FIELDS = [
        'categories',
        'confidence',
        'review_required',
        'reason_codes',
        'evidence_refs',
    ];

    private const ALLOWED_FIELDS = [
        'categories',
        'confidence',
        'review_required',
        'reason_codes',
        'evidence_refs',
        'none_applies',
        'structural_conflict',
        'inconclusive_reason',
    ];

    public function __construct(
        private EvidenceReferenceVerifier $evidence,
        private float $categoryThreshold = 0.60,
    ) {
        if (! is_finite($categoryThreshold) || $categoryThreshold < 0 || $categoryThreshold > 1) {
            throw new InvalidClassificationProposal('Limiar de classificação inválido.');
        }
    }

    /** @param array<string, mixed> $output
     * @param  list<Category>  $activeCategories
     */
    public function validate(
        array $output,
        GatedClassificationInput $input,
        array $activeCategories,
        string $providerName,
        ?string $providerModel,
    ): ClassificationProposal {
        $keys = array_keys($output);

        if (array_diff($keys, self::ALLOWED_FIELDS) !== []
            || array_diff(self::REQUIRED_FIELDS, $keys) !== []) {
            throw new InvalidClassificationProposal('Formato de proposta do classificador inválido.');
        }

        if ($input->threadId < 1 || trim($providerName) === '') {
            throw new InvalidClassificationProposal('Proveniência da proposta inválida.');
        }

        $confidence = $this->probability($output['confidence'], 'confidence');

        if (! is_bool($output['review_required'])
            || ! is_array($output['categories'])
            || ! array_is_list($output['categories'])
            || ! is_array($output['reason_codes'])
            || ! array_is_list($output['reason_codes'])
            || ! is_array($output['evidence_refs'])
            || ! array_is_list($output['evidence_refs'])) {
            throw new InvalidClassificationProposal('Formato de proposta do classificador inválido.');
        }

        $noneApplies = $output['none_applies'] ?? false;
        $structuralConflict = $output['structural_conflict'] ?? false;

        if (! is_bool($noneApplies) || ! is_bool($structuralConflict)) {
            throw new InvalidClassificationProposal('Sinal de proposta inválido.');
        }

        if ($noneApplies && ($output['categories'] !== [] || $structuralConflict)) {
            throw new InvalidClassificationProposal('Proposta contraditória entre Categoria, ausência e conflito.');
        }

        $activeById = [];

        foreach ($activeCategories as $category) {
            if (! $category instanceof Category || ! $category->isActive()) {
                throw new InvalidClassificationProposal('Allowlist contém Categoria não ativa.');
            }

            $activeById[$category->id] = $category;
        }

        $categories = [];
        $seenCategoryIds = [];

        foreach ($output['categories'] as $candidate) {
            if (! is_array($candidate)
                || array_is_list($candidate)
                || array_diff(array_keys($candidate), ['category_id', 'probability']) !== []
                || array_diff(['category_id', 'probability'], array_keys($candidate)) !== []) {
                throw new InvalidClassificationProposal('Candidata de Categoria malformada.');
            }

            $categoryId = $candidate['category_id'];

            if (! is_int($categoryId) || $categoryId < 1 || ! isset($activeById[$categoryId])) {
                throw new InvalidClassificationProposal('Proposta contém Categoria desconhecida ou arquivada.');
            }

            if (isset($seenCategoryIds[$categoryId])) {
                throw new InvalidClassificationProposal('Proposta contém Categoria duplicada.');
            }

            $seenCategoryIds[$categoryId] = true;
            $categories[] = new ProposedCategory(
                $activeById[$categoryId]->currentVersion,
                $this->probability($candidate['probability'], 'category probability'),
            );
        }

        $reasonCodes = $this->reasonCodes($output['reason_codes']);
        $suppliedReason = $output['inconclusive_reason'] ?? null;
        $inconclusiveReason = $this->inconclusiveReason($suppliedReason);

        if (in_array(InconclusiveReason::Conflict->value, $reasonCodes, true)) {
            $structuralConflict = true;
        }

        if ($inconclusiveReason === InconclusiveReason::Conflict) {
            $structuralConflict = true;
        }

        if ($structuralConflict && $noneApplies) {
            throw new InvalidClassificationProposal('Proposta contraditória entre ausência e conflito.');
        }

        $abstentionReason = $inconclusiveReason;

        if ($abstentionReason === null) {
            foreach ([
                InconclusiveReason::LowConfidence,
                InconclusiveReason::NoMatch,
                InconclusiveReason::InsufficientContent,
            ] as $reason) {
                if (in_array($reason->value, $reasonCodes, true)) {
                    $abstentionReason = $reason;
                    break;
                }
            }
        }

        if ($structuralConflict) {
            if ($inconclusiveReason !== null && $inconclusiveReason !== InconclusiveReason::Conflict) {
                throw new InvalidClassificationProposal('Motivo incompatível com conflito estrutural.');
            }

            $outcome = ProposalOutcome::Inconclusive;
            $inconclusiveReason = InconclusiveReason::Conflict;
        } elseif ($abstentionReason !== null) {
            $outcome = ProposalOutcome::Inconclusive;
            $inconclusiveReason = $abstentionReason;
        } elseif ($noneApplies && $confidence >= $this->categoryThreshold) {
            $outcome = ProposalOutcome::Uncategorized;
            $inconclusiveReason = null;
        } elseif ($categories !== []
            && $confidence >= $this->categoryThreshold
            && $this->hasCandidateAtOrAboveThreshold($categories)) {
            $outcome = ProposalOutcome::Categorized;
            $inconclusiveReason = null;
        } else {
            $outcome = ProposalOutcome::Inconclusive;
            $inconclusiveReason ??= $confidence < $this->categoryThreshold
                ? InconclusiveReason::LowConfidence
                : ($categories === [] ? InconclusiveReason::NoMatch : InconclusiveReason::LowConfidence);
        }

        if ($outcome === ProposalOutcome::Inconclusive) {
            $reasonCodes[] = $inconclusiveReason->value;
        }

        return new ClassificationProposal(
            $input->threadId,
            $outcome,
            $categories,
            $confidence,
            true,
            array_values(array_unique($reasonCodes)),
            $this->verifiedEvidenceReferences($output['evidence_refs'], $input->threadId),
            $outcome === ProposalOutcome::Inconclusive ? $inconclusiveReason : null,
            trim($providerName),
            $providerModel === null ? null : trim($providerModel),
        );
    }

    private function probability(mixed $value, string $field): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
            || $value < 0 || $value > 1) {
            throw new InvalidClassificationProposal("{$field} deve estar entre 0 e 1.");
        }

        return (float) $value;
    }

    /** @param array<mixed> $values
     * @return list<string>
     */
    private function reasonCodes(array $values): array
    {
        $codes = [];

        foreach ($values as $value) {
            if (! is_string($value) || InconclusiveReason::tryFrom($value) === null) {
                throw new InvalidClassificationProposal('Código de razão não permitido.');
            }

            $codes[] = $value;
        }

        return array_values(array_unique($codes));
    }

    private function inconclusiveReason(mixed $value): ?InconclusiveReason
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ($reason = InconclusiveReason::tryFrom($value)) === null) {
            throw new InvalidClassificationProposal('Motivo de inconclusão não permitido.');
        }

        return $reason;
    }

    /** @param array<mixed> $references
     * @return list<string>
     */
    private function verifiedEvidenceReferences(array $references, int $threadId): array
    {
        $verified = [];

        foreach ($references as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw new InvalidClassificationProposal('Referência de Evidência malformada.');
            }

            $reference = trim($reference);

            if ($this->evidence->isVerifiedLocalReference($threadId, $reference)) {
                $verified[] = $reference;
            }
        }

        return array_values(array_unique($verified));
    }

    /** @param list<ProposedCategory> $categories */
    private function hasCandidateAtOrAboveThreshold(array $categories): bool
    {
        foreach ($categories as $category) {
            if ($category->probability >= $this->categoryThreshold) {
                return true;
            }
        }

        return false;
    }
}
