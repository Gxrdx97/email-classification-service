<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

use App\Domain\Privacy\Enums\PrivacyAction;
use InvalidArgumentException;

final readonly class PreAnalysisContext
{
    /** @var list<string> */
    private const MESSAGE_FIELDS = ['from', 'subject', 'sent_at', 'body'];

    /**
     * @param  array<string, mixed>  $thread
     * @param  list<PreAnalysisCategoryVersion>  $categoryVersions
     * @param  list<PreAnalysisExample>  $examples
     */
    public function __construct(
        public PreAnalysisIdentity $identity,
        public bool $cursorCommitted,
        public bool $hasOfficialResult,
        public PrivacyAction $privacyAction,
        public string $questionVersion,
        public string $schemaVersion,
        public string $question,
        public array $thread,
        public array $categoryVersions,
        public array $examples,
    ) {}

    /** @return array<string, mixed> */
    public function providerPayload(): array
    {
        try {
            $thread = $this->providerThread();
        } catch (InvalidArgumentException) {
            return [];
        }

        return [
            'question' => $this->question,
            'thread' => $thread,
            'categories' => array_map(
                static fn (PreAnalysisCategoryVersion $category): array => $category->toProviderPayload(),
                $this->categoryVersions,
            ),
            'examples' => array_map(
                static fn (PreAnalysisExample $example): array => $example->toProviderPayload(),
                $this->eligibleExamples(),
            ),
        ];
    }

    /** @return array{messages: list<array<string, string|null>>} */
    private function providerThread(): array
    {
        foreach ($this->thread as $key => $value) {
            if ($key === 'attachments') {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('The pre-analysis Thread contains unsupported data.');
                }

                continue;
            }

            if ($key !== 'messages' || ! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException('The pre-analysis Thread contains unsupported data.');
            }
        }

        $messages = $this->thread['messages'] ?? null;

        if (! is_array($messages) || ! array_is_list($messages)) {
            throw new InvalidArgumentException('The pre-analysis Thread contains unsupported data.');
        }

        $providerMessages = [];

        foreach ($messages as $message) {
            if (! is_array($message) || array_is_list($message)) {
                throw new InvalidArgumentException('The pre-analysis Thread contains unsupported data.');
            }

            $providerMessage = [];

            foreach ($message as $field => $value) {
                if (! is_string($field)
                    || ! in_array($field, self::MESSAGE_FIELDS, true)
                    || (! is_string($value) && $value !== null)) {
                    throw new InvalidArgumentException('The pre-analysis Thread contains unsupported data.');
                }

                $providerMessage[$field] = $value;
            }

            $providerMessages[] = $providerMessage;
        }

        return ['messages' => $providerMessages];
    }

    /** @return list<int> */
    public function allowedCategoryVersionIds(): array
    {
        return array_values(array_map(
            static fn (PreAnalysisCategoryVersion $category): int => $category->id,
            $this->categoryVersions,
        ));
    }

    /** @return list<array{id: int, version: int, category_version_id: int}> */
    public function exampleProvenance(): array
    {
        return array_map(
            static fn (PreAnalysisExample $example): array => $example->provenance(),
            $this->eligibleExamples(),
        );
    }

    /** @return list<PreAnalysisExample> */
    private function eligibleExamples(): array
    {
        $allowedCategoryVersionIds = array_fill_keys($this->allowedCategoryVersionIds(), true);

        return array_values(array_filter(
            $this->examples,
            static fn (PreAnalysisExample $example): bool => isset($allowedCategoryVersionIds[$example->categoryVersionId]),
        ));
    }
}
