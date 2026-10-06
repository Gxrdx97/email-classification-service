<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\CategoryRepository;
use App\Domain\Classification\Contracts\ClassificationOperationalStateRepository;
use App\Domain\Classification\Contracts\ClassificationPayloadGate;
use App\Domain\Classification\Contracts\ClassifierProvider;
use App\Domain\Classification\Data\ClassificationContext;
use App\Domain\Classification\Data\ClassificationFailure;
use App\Domain\Classification\Data\ClassificationProposal;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Exceptions\ClassifierProviderFailure;
use App\Domain\Classification\Exceptions\InvalidClassificationProposal;

final readonly class ClassificationService
{
    public function __construct(
        private CategoryRepository $categories,
        private ClassifierProvider $provider,
        private ClassificationPayloadGate $payloadGate,
        private ClassificationOperationalStateRepository $operationalState,
        private ClassificationProposalValidator $validator,
    ) {}

    public function propose(ClassificationContext $context): ClassificationProposal|ClassificationFailure
    {
        $activeCategories = $this->categories->listActive();
        $gatedInput = $this->payloadGate->prepare($context, $activeCategories);
        $activeCategoryVersions = $this->categoryVersionMap($activeCategories);
        $gatedCategoryVersions = $this->categoryVersionMap($gatedInput->activeCategories);

        if ($gatedInput->threadId !== $context->threadId
            || count($activeCategoryVersions) !== count($activeCategories)
            || count($gatedCategoryVersions) !== count($gatedInput->activeCategories)
            || $gatedCategoryVersions !== $activeCategoryVersions) {
            throw new InvalidClassificationProposal('O Gate alterou o escopo confiável da classificação.');
        }

        try {
            $output = $this->provider->propose($gatedInput);
        } catch (ClassifierProviderFailure $failure) {
            $this->operationalState->markFailed($context->threadId);

            return new ClassificationFailure($context->threadId, $failure->failureCode);
        }

        return $this->validator->validate(
            $output,
            $gatedInput,
            $activeCategories,
            $this->provider->name(),
            $this->provider->model(),
        );
    }

    /** @param list<Category> $categories
     * @return array<int, int>
     */
    private function categoryVersionMap(array $categories): array
    {
        $versions = [];

        foreach ($categories as $category) {
            $versions[$category->id] = $category->currentVersion->id;
        }

        ksort($versions);

        return $versions;
    }
}
