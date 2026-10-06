<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Services;

use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use App\Domain\PreAnalysis\Data\PreAnalysisSuggestion;
use App\Domain\PreAnalysis\Exceptions\InvalidPreAnalysisOutput;

final class PreAnalysisOutputValidator
{
    /** @var list<string> */
    private const UNCERTAINTY_CODES = [
        'low_confidence',
        'conflict',
        'no_match',
        'insufficient_content',
    ];

    /**
     * @param  list<int>  $allowedCategoryVersionIds
     * @param  array<string, int>  $usage
     */
    public function validate(
        mixed $output,
        array $allowedCategoryVersionIds,
        array $usage = [],
    ): PreAnalysisProposal {
        if (! is_array($output)
            || array_is_list($output)
            || ! $this->hasExactKeys($output, ['suggestions', 'none_applies', 'inconclusive_reason'])
            || ! is_array($output['suggestions'])
            || ! array_is_list($output['suggestions'])
            || count($output['suggestions']) > 40
            || ! is_bool($output['none_applies'])
            || ! (is_string($output['inconclusive_reason']) || $output['inconclusive_reason'] === null)) {
            throw new InvalidPreAnalysisOutput;
        }

        $allowed = array_fill_keys($allowedCategoryVersionIds, true);
        $seen = [];
        $suggestions = [];

        foreach ($output['suggestions'] as $item) {
            if (! is_array($item)
                || array_is_list($item)
                || ! $this->hasExactKeys($item, [
                    'category_version_id',
                    'probability',
                    'uncertainty_code',
                    'structural_conflict',
                ])) {
                throw new InvalidPreAnalysisOutput;
            }

            $categoryVersionId = $item['category_version_id'];
            $probability = $item['probability'];
            $uncertaintyCode = $item['uncertainty_code'];

            if (! is_int($categoryVersionId)
                || ! isset($allowed[$categoryVersionId])
                || isset($seen[$categoryVersionId])
                || (! is_int($probability) && ! is_float($probability))
                || ! is_finite((float) $probability)
                || $probability < 0
                || $probability > 1
                || ! is_bool($item['structural_conflict'])
                || ! ($uncertaintyCode === null
                    || (is_string($uncertaintyCode) && in_array($uncertaintyCode, self::UNCERTAINTY_CODES, true)))) {
                throw new InvalidPreAnalysisOutput;
            }

            $seen[$categoryVersionId] = true;
            $suggestions[] = new PreAnalysisSuggestion(
                categoryVersionId: $categoryVersionId,
                probability: (float) $probability,
                uncertaintyCode: $uncertaintyCode,
                structuralConflict: $item['structural_conflict'],
            );
        }

        $noneApplies = $output['none_applies'];
        $inconclusiveReason = $output['inconclusive_reason'];

        if (($noneApplies && ($suggestions !== [] || $inconclusiveReason !== null))
            || (! $noneApplies && $suggestions === [] && $inconclusiveReason === null)) {
            throw new InvalidPreAnalysisOutput;
        }

        if ($inconclusiveReason !== null && ! in_array($inconclusiveReason, self::UNCERTAINTY_CODES, true)) {
            throw new InvalidPreAnalysisOutput;
        }

        foreach ($usage as $key => $value) {
            if (! in_array($key, ['prompt_tokens', 'completion_tokens', 'total_tokens'], true)
                || ! is_int($value)
                || $value < 0) {
                throw new InvalidPreAnalysisOutput;
            }
        }

        return new PreAnalysisProposal($suggestions, $noneApplies, $inconclusiveReason, $usage);
    }

    /** @param array<string, mixed> $values */
    private function hasExactKeys(array $values, array $keys): bool
    {
        $actualKeys = array_keys($values);
        sort($actualKeys);
        sort($keys);

        return $actualKeys === $keys;
    }
}
