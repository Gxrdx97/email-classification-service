<?php

declare(strict_types=1);

namespace Tests\Unit\Classification\Support;

use App\Domain\Classification\Contracts\CategoryRepository;
use App\Domain\Classification\Contracts\ClassificationOperationalStateRepository;
use App\Domain\Classification\Contracts\ClassificationPayloadGate;
use App\Domain\Classification\Contracts\ClassificationReviewRepository;
use App\Domain\Classification\Contracts\ClassifierProvider;
use App\Domain\Classification\Contracts\EvidenceReferenceVerifier;
use App\Domain\Classification\Contracts\ReviewActorProvider;
use App\Domain\Classification\Data\CategoryDefinition;
use App\Domain\Classification\Data\ClassificationContext;
use App\Domain\Classification\Data\GatedClassificationInput;
use App\Domain\Classification\Data\ReviewActor;
use App\Domain\Classification\Data\ReviewDecision;
use App\Domain\Classification\Data\ReviewReceipt;
use App\Domain\Classification\Entities\Category;
use App\Domain\Classification\Entities\CategoryVersion;
use App\Domain\Classification\Enums\OperationalState;
use App\Domain\Classification\Enums\ReviewScope;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\CategoryNotFound;
use App\Domain\Classification\Exceptions\ClassifierProviderFailure;
use App\Domain\Classification\Exceptions\ReviewIdempotencyConflict;
use Closure;
use DateTimeImmutable;

final class InMemoryCategoryRepository implements CategoryRepository
{
    /** @var array<int, Category> */
    private array $categories = [];

    /** @var array<int, CategoryVersion> */
    private array $versions = [];

    public function __construct(Category ...$categories)
    {
        foreach ($categories as $category) {
            $this->categories[$category->id] = $category;
            $this->versions[$category->currentVersion->id] = $category->currentVersion;
        }
    }

    public function create(CategoryDefinition $definition): Category
    {
        $categoryId = $this->categories === [] ? 1 : max(array_keys($this->categories)) + 1;
        $version = $this->makeVersion($categoryId, 1, $definition);
        $category = new Category($categoryId, $definition->slug, null, $version);
        $this->categories[$categoryId] = $category;

        return $category;
    }

    public function revise(int $categoryId, CategoryDefinition $definition): Category
    {
        $current = $this->categories[$categoryId] ?? throw new CategoryNotFound;

        if (! $current->isActive()) {
            throw new CategoryNotActive;
        }

        $version = $this->makeVersion($categoryId, $current->currentVersion->versionNumber + 1, $definition);
        $category = new Category($categoryId, $definition->slug, null, $version);
        $this->categories[$categoryId] = $category;

        return $category;
    }

    public function archive(int $categoryId): Category
    {
        $current = $this->categories[$categoryId] ?? throw new CategoryNotFound;
        $category = new Category($categoryId, $current->slug, new DateTimeImmutable, $current->currentVersion);
        $this->categories[$categoryId] = $category;

        return $category;
    }

    public function findVersion(int $categoryVersionId): ?CategoryVersion
    {
        return $this->versions[$categoryVersionId] ?? null;
    }

    public function listActive(): array
    {
        return array_values(array_filter($this->categories, static fn (Category $category): bool => $category->isActive()));
    }

    public function findActiveVersionsByCategoryIds(array $categoryIds): array
    {
        $versions = [];

        foreach ($categoryIds as $categoryId) {
            $category = $this->categories[$categoryId] ?? null;

            if ($category?->isActive()) {
                $versions[] = $category->currentVersion;
            }
        }

        return $versions;
    }

    private function makeVersion(int $categoryId, int $versionNumber, CategoryDefinition $definition): CategoryVersion
    {
        $versionId = $this->versions === [] ? 1 : max(array_keys($this->versions)) + 1;
        $version = new CategoryVersion(
            $versionId,
            $categoryId,
            $versionNumber,
            $definition->name,
            $definition->slug,
            $definition->description,
            $definition->classificationInstructions,
            $definition->examplesEnabled,
        );
        $this->versions[$versionId] = $version;

        return $version;
    }
}

final class StaticEvidenceVerifier implements EvidenceReferenceVerifier
{
    /** @param list<string> $verified */
    public function __construct(private array $verified = []) {}

    public function isVerifiedLocalReference(int $threadId, string $reference): bool
    {
        return in_array($reference, $this->verified, true);
    }
}

final class StaticReviewActorProvider implements ReviewActorProvider
{
    public function __construct(private string $subject = 'user@example.test') {}

    public function currentActor(): ReviewActor
    {
        return new ReviewActor($this->subject);
    }
}

final class PassThroughClassificationGate implements ClassificationPayloadGate
{
    public int $calls = 0;

    /** @var list<int> */
    public array $activeCategoryIds = [];

    public function __construct(private ?Closure $transform = null) {}

    public function prepare(ClassificationContext $context, array $activeCategories): GatedClassificationInput
    {
        $this->calls++;
        $this->activeCategoryIds = array_map(static fn (Category $category): int => $category->id, $activeCategories);
        $payload = $this->transform === null
            ? $context->enrichedThread
            : ($this->transform)($context->enrichedThread);

        return new GatedClassificationInput($context->threadId, $payload, $activeCategories);
    }
}

final class FakeClassifierProvider implements ClassifierProvider
{
    public ?GatedClassificationInput $lastInput = null;

    public int $calls = 0;

    /** @param array<string, mixed> $output */
    public function __construct(
        private array $output,
        private string $providerName = 'rules',
        private ?string $providerModel = 'rules-v1',
        private ?ClassifierProviderFailure $failure = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): ?string
    {
        return $this->providerModel;
    }

    public function propose(GatedClassificationInput $input): array
    {
        $this->calls++;
        $this->lastInput = $input;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->output;
    }
}

final class InMemoryOperationalStateRepository implements ClassificationOperationalStateRepository
{
    /** @var array<int, OperationalState> */
    public array $states = [];

    /** @var array<int, int|null> */
    public array $officialClassificationIds = [];

    public function markPending(int $threadId): void
    {
        $this->states[$threadId] = OperationalState::Pending;
    }

    public function markFailed(int $threadId): void
    {
        $this->states[$threadId] = OperationalState::Failed;
    }
}

final class RecordingReviewRepository implements ClassificationReviewRepository
{
    /** @var list<ReviewDecision> */
    public array $decisions = [];

    /** @var array<string, ReviewReceipt> */
    private array $receipts = [];

    /** @var array<string, ReviewDecision> */
    private array $decisionsByKey = [];

    /** @param list<int> $activeCategoryIds */
    public function __construct(private array $activeCategoryIds = [1, 2]) {}

    public function record(ReviewDecision $decision): ReviewReceipt
    {
        if (array_diff($decision->categoryIds, $this->activeCategoryIds) !== []) {
            throw new CategoryNotActive('A seleção contém Categoria desconhecida ou arquivada.');
        }

        $key = $decision->threadId.':'.$decision->idempotencyKey;

        if (isset($this->receipts[$key])) {
            $prior = $this->decisionsByKey[$key];

            if ($prior != $decision) {
                throw new ReviewIdempotencyConflict;
            }

            $receipt = $this->receipts[$key];

            return new ReviewReceipt($receipt->reviewId, $receipt->scope, $receipt->officialClassificationId, true);
        }

        $this->decisions[] = $decision;
        $this->decisionsByKey[$key] = $decision;
        $reviewId = count($this->decisions);
        $officialId = $decision->scope === ReviewScope::Thread && $decision->outcome !== null ? $reviewId : null;
        $receipt = new ReviewReceipt($reviewId, $decision->scope, $officialId, false);
        $this->receipts[$key] = $receipt;

        return $receipt;
    }
}
