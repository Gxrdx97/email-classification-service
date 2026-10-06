<?php

declare(strict_types=1);

use App\Domain\GmailLabels\Contracts\CategoryLabelGateway;
use App\Domain\GmailLabels\Contracts\CategoryLabelMappingRepository;
use App\Domain\GmailLabels\Contracts\OfficialDecisionSource;
use App\Domain\GmailLabels\Contracts\ProjectionIntentDispatcher;
use App\Domain\GmailLabels\Contracts\ProjectionOutboxRepository;
use App\Domain\GmailLabels\Data\CategoryLabelMapping;
use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\OfficialDecision;
use App\Domain\GmailLabels\Data\ProjectionIntent;
use App\Domain\GmailLabels\Data\ProjectionMessage;
use App\Domain\GmailLabels\Data\ProjectionOutboxReceipt;
use App\Domain\GmailLabels\Data\ThreadProjectionSnapshot;
use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use App\Domain\GmailLabels\Exceptions\ProjectionDeliveryException;
use App\Domain\GmailLabels\Services\ConsumeCategoryLabelIntent;
use App\Domain\GmailLabels\Services\MessageLabelProjectionResolver;
use App\Domain\GmailLabels\Services\ProjectCategoryLabels;

final class GmailLabelsFakeDecisionSource implements OfficialDecisionSource
{
    public function __construct(public ?ThreadProjectionSnapshot $snapshot) {}

    public function threadSnapshot(int $threadId): ?ThreadProjectionSnapshot
    {
        return $this->snapshot;
    }
}

final class GmailLabelsFakeOutbox implements ProjectionOutboxRepository
{
    /** @var array<int, array{projection: DesiredMessageLabelProjection, key: string, hash: string, generation: int, attempt: int, status: string}> */
    public array $items = [];

    /** @var array<string, int> */
    private array $keys = [];

    public function record(DesiredMessageLabelProjection $projection, string $idempotencyKey): ProjectionOutboxReceipt
    {
        if (isset($this->keys[$idempotencyKey])) {
            $id = $this->keys[$idempotencyKey];

            return new ProjectionOutboxReceipt($id, $this->items[$id]['projection']->messageId);
        }

        $id = count($this->items) + 1;
        $hash = hash('sha256', $projection->decision->state->value.':'.implode(',', $projection->categoryIds));
        $generation = 1;

        foreach ($this->items as $item) {
            if ($item['projection']->messageId === $projection->messageId) {
                $generation = max($generation, $item['generation'] + 1);
            }
        }

        $this->items[$id] = [
            'projection' => $projection,
            'key' => $idempotencyKey,
            'hash' => $hash,
            'generation' => $generation,
            'attempt' => 0,
            'status' => 'pending',
        ];
        $this->keys[$idempotencyKey] = $id;

        return new ProjectionOutboxReceipt($id, $projection->messageId);
    }

    public function claim(int $outboxId): ?ProjectionIntent
    {
        if (! isset($this->items[$outboxId]) || in_array($this->items[$outboxId]['status'], ['succeeded', 'dead', 'superseded'], true)) {
            return null;
        }

        $messageId = $this->items[$outboxId]['projection']->messageId;
        $latestGeneration = max(array_map(
            static fn (array $item): int => $item['projection']->messageId === $messageId ? $item['generation'] : 0,
            $this->items,
        ));

        if ($this->items[$outboxId]['generation'] < $latestGeneration) {
            $this->items[$outboxId]['status'] = 'superseded';

            return null;
        }

        $this->items[$outboxId]['attempt']++;
        $this->items[$outboxId]['status'] = 'processing';

        return new ProjectionIntent(
            $outboxId,
            $this->items[$outboxId]['generation'],
            $this->items[$outboxId]['projection'],
            $this->items[$outboxId]['key'],
            $this->items[$outboxId]['hash'],
            $this->items[$outboxId]['attempt'],
        );
    }

    public function supersede(ProjectionIntent $intent, string $reasonCode): void
    {
        if (in_array($this->items[$intent->outboxId]['status'], ['pending', 'processing', 'retryable'], true)) {
            $this->items[$intent->outboxId]['status'] = 'superseded';
        }
    }

    public function supersedeActiveForMessage(int $messageId, string $reasonCode): void
    {
        foreach ($this->items as $id => $item) {
            if ($item['projection']->messageId === $messageId
                && in_array($item['status'], ['pending', 'processing', 'retryable'], true)) {
                $this->items[$id]['status'] = 'superseded';
            }
        }
    }

    public function markApplied(ProjectionIntent $intent): void
    {
        $this->items[$intent->outboxId]['status'] = 'succeeded';
    }

    public function markRetryable(ProjectionIntent $intent, string $errorCode, DateTimeImmutable $availableAt): void
    {
        $this->items[$intent->outboxId]['status'] = 'retryable';
    }

    public function markDead(int $outboxId, string $errorCode): void
    {
        $this->items[$outboxId]['status'] = 'dead';
    }
}

final class GmailLabelsFakeDispatcher implements ProjectionIntentDispatcher
{
    /** @var list<array{outbox_id: int, message_id: int}> */
    public array $dispatched = [];

    public function dispatchAfterCommit(int $outboxId, int $messageId): void
    {
        $this->dispatched[] = ['outbox_id' => $outboxId, 'message_id' => $messageId];
    }
}

final class GmailLabelsFakeMappings implements CategoryLabelMappingRepository
{
    /** @param array<int, CategoryLabelMapping> $stored
     * @param  array<int, string>  $names
     */
    public function __construct(public array $stored = [], public array $names = []) {}

    public function find(int $categoryId): ?CategoryLabelMapping
    {
        return $this->stored[$categoryId] ?? null;
    }

    public function displayName(int $categoryId): ?string
    {
        return $this->names[$categoryId] ?? null;
    }

    public function saveIfMissing(int $categoryId, string $displayName, string $providerLabelId): CategoryLabelMapping
    {
        return $this->stored[$categoryId] ??= new CategoryLabelMapping($categoryId, $displayName, $providerLabelId);
    }

    public function allManaged(): array
    {
        return array_values($this->stored);
    }
}

final class GmailLabelsFakeProjectionGateway implements CategoryLabelGateway
{
    /** @var list<array{gmail_message_id: string, desired_ids: list<string>}> */
    public array $reconciliations = [];

    public bool $failNext = false;

    public int $ensureCalls = 0;

    public ?Closure $afterEnsure = null;

    public function ensureCategoryLabel(int $categoryId, string $displayName): CategoryLabelMapping
    {
        $this->ensureCalls++;
        ($this->afterEnsure)?->__invoke();

        return new CategoryLabelMapping($categoryId, $displayName, 'Label_'.$categoryId);
    }

    public function reconcileMessageAssociations(string $gmailMessageId, array $desiredManagedLabelIds): void
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('Synthetic Gmail failure.');
        }

        $this->reconciliations[] = [
            'gmail_message_id' => $gmailMessageId,
            'desired_ids' => $desiredManagedLabelIds,
        ];
    }
}

function gmailLabelsDecision(
    int $decisionId,
    DecisionScope $scope,
    OfficialDecisionState $state = OfficialDecisionState::CATEGORIZED,
    array $categoryIds = [4],
    ?int $messageId = null,
): OfficialDecision {
    return new OfficialDecision(
        decisionId: $decisionId,
        reviewId: $scope === DecisionScope::MESSAGE ? $decisionId + 100 : $decisionId + 100,
        threadId: 11,
        scope: $scope,
        targetMessageId: $messageId,
        state: $state,
        categoryIds: $categoryIds,
        decisionAuthor: 'human_review',
    );
}

function gmailLabelsThreadSnapshot(
    ?OfficialDecision $threadDecision,
    array $messages,
    string $operationalState = 'categorized',
): ThreadProjectionSnapshot {
    return new ThreadProjectionSnapshot(3, 11, $operationalState, $threadDecision, $messages);
}

function gmailLabelsProjector(
    GmailLabelsFakeDecisionSource $source,
    GmailLabelsFakeOutbox $outbox,
    GmailLabelsFakeDispatcher $dispatcher,
): ProjectCategoryLabels {
    return new ProjectCategoryLabels($source, new MessageLabelProjectionResolver, $outbox, $dispatcher);
}

function gmailLabelsMessage(int $messageId, ?OfficialDecision $override = null): ProjectionMessage
{
    return new ProjectionMessage(3, $messageId, 11, 'gmail-message-'.$messageId, $override);
}

describe('projeção de Labels a partir de decisões oficiais', function () {
    it('official_thread_categories_project_to_all_messages_safely', function () {
        $decision = gmailLabelsDecision(30, DecisionScope::THREAD, categoryIds: [4, 5]);
        $snapshot = gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21), gmailLabelsMessage(22)], 'pending');
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $projector = gmailLabelsProjector(new GmailLabelsFakeDecisionSource($snapshot), $outbox, $dispatcher);

        $count = $projector->projectThread(11);

        expect($count)->toBe(2)
            ->and(array_values(array_map(fn ($item) => $item['projection']->categoryIds, $outbox->items)))->toBe([[4, 5], [4, 5]])
            ->and(array_values(array_map(fn ($item) => $item['projection']->decision->scope, $outbox->items)))->toBe([DecisionScope::THREAD, DecisionScope::THREAD])
            ->and($dispatcher->dispatched)->toHaveCount(2);
    });

    it('message_scoped_correction_projects_only_to_target_message', function () {
        $threadDecision = gmailLabelsDecision(31, DecisionScope::THREAD, categoryIds: [4]);
        $messageDecision = gmailLabelsDecision(32, DecisionScope::MESSAGE, categoryIds: [8], messageId: 21);
        $snapshot = gmailLabelsThreadSnapshot($threadDecision, [
            gmailLabelsMessage(21, $messageDecision),
            gmailLabelsMessage(22),
        ]);
        $outbox = new GmailLabelsFakeOutbox;
        $projector = gmailLabelsProjector(
            new GmailLabelsFakeDecisionSource($snapshot),
            $outbox,
            new GmailLabelsFakeDispatcher,
        );

        $projector->projectThread(11);

        expect($outbox->items[1]['projection']->categoryIds)->toBe([8])
            ->and($outbox->items[1]['projection']->decision->scope)->toBe(DecisionScope::MESSAGE)
            ->and($outbox->items[2]['projection']->categoryIds)->toBe([4])
            ->and($outbox->items[2]['projection']->decision->scope)->toBe(DecisionScope::THREAD);
    });

    it('nonofficial_classification_states_do_not_write_gmail', function () {
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $message = gmailLabelsMessage(21);

        foreach (['pending', 'inconclusive', 'failed'] as $operationalState) {
            $source = new GmailLabelsFakeDecisionSource(
                gmailLabelsThreadSnapshot(null, [$message], $operationalState),
            );
            gmailLabelsProjector($source, $outbox, $dispatcher)->projectThread(11);
        }

        $inconclusive = gmailLabelsDecision(35, DecisionScope::THREAD, OfficialDecisionState::INCONCLUSIVE, []);
        $failed = gmailLabelsDecision(36, DecisionScope::THREAD, OfficialDecisionState::FAILED, []);
        foreach ([$inconclusive, $failed] as $nonActionable) {
            gmailLabelsProjector(
                new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot($nonActionable, [$message])),
                $outbox,
                $dispatcher,
            )->projectThread(11);
        }

        expect($outbox->items)->toBe([])
            ->and($dispatcher->dispatched)->toBe([]);
    });

    it('mantém override Message-only e supersede a intent Thread após decisão inconclusive', function () {
        $threadDecision = gmailLabelsDecision(41, DecisionScope::THREAD, categoryIds: [4]);
        $source = new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot(
            $threadDecision,
            [gmailLabelsMessage(21), gmailLabelsMessage(22)],
        ));
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $projector = gmailLabelsProjector($source, $outbox, $dispatcher);
        $projector->projectThread(11);

        $inconclusive = gmailLabelsDecision(42, DecisionScope::THREAD, OfficialDecisionState::INCONCLUSIVE, []);
        $messageOverride = gmailLabelsDecision(43, DecisionScope::MESSAGE, categoryIds: [8], messageId: 21);
        $source->snapshot = gmailLabelsThreadSnapshot($inconclusive, [
            gmailLabelsMessage(21, $messageOverride),
            gmailLabelsMessage(22),
        ], 'inconclusive');

        expect($projector->projectThread(11))->toBe(1)
            ->and($outbox->items[2]['status'])->toBe('superseded')
            ->and($outbox->items[3]['projection']->messageId)->toBe(21)
            ->and($outbox->items[3]['projection']->decision->scope)->toBe(DecisionScope::MESSAGE)
            ->and($outbox->items[3]['projection']->categoryIds)->toBe([8])
            ->and($dispatcher->dispatched)->toHaveCount(3);

        expect($outbox->claim(1))->toBeNull()
            ->and($outbox->items[1]['status'])->toBe('superseded');
    });

    it('uncategorized_clears_managed_labels_and_nonofficial_states_do_not_write', function () {
        $decision = gmailLabelsDecision(37, DecisionScope::THREAD, OfficialDecisionState::UNCATEGORIZED, []);
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $projector = gmailLabelsProjector(
            new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)])),
            $outbox,
            $dispatcher,
        );

        $projector->projectThread(11);

        $nonofficial = gmailLabelsProjector(
            new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot(null, [gmailLabelsMessage(22)], 'pending')),
            $outbox,
            $dispatcher,
        );
        $nonofficial->projectThread(11);

        expect($outbox->items[1]['projection']->categoryIds)->toBe([])
            ->and($outbox->items[1]['projection']->decision->state)->toBe(OfficialDecisionState::UNCATEGORIZED)
            ->and($outbox->items)->toHaveCount(1);
    });

    it('duplicate_projection_outbox_delivery_is_idempotent', function () {
        $decision = gmailLabelsDecision(38, DecisionScope::THREAD, categoryIds: [4]);
        $snapshot = gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)]);
        $source = new GmailLabelsFakeDecisionSource($snapshot);
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $projector = gmailLabelsProjector($source, $outbox, $dispatcher);
        $projector->projectThread(11);
        $projector->projectThread(11);

        $gateway = new GmailLabelsFakeProjectionGateway;
        $consumer = new ConsumeCategoryLabelIntent(
            $outbox,
            new GmailLabelsFakeMappings([4 => new CategoryLabelMapping(4, 'Categoria', 'Label_4')]),
            $gateway,
            $source,
            new MessageLabelProjectionResolver,
        );
        $consumer->consume(1);
        $consumer->consume(1);

        expect($outbox->items)->toHaveCount(1)
            ->and($gateway->reconciliations)->toHaveCount(1)
            ->and($gateway->reconciliations[0]['desired_ids'])->toBe(['Label_4']);
    });

    it('new_message_inherits_thread_label_without_reclassification', function () {
        $decision = gmailLabelsDecision(39, DecisionScope::THREAD, categoryIds: [4]);
        $source = new GmailLabelsFakeDecisionSource(
            gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)], 'categorized'),
        );
        $outbox = new GmailLabelsFakeOutbox;
        $dispatcher = new GmailLabelsFakeDispatcher;
        $projector = gmailLabelsProjector($source, $outbox, $dispatcher);

        $projector->projectThread(11);
        $source->snapshot = gmailLabelsThreadSnapshot(
            $decision,
            [gmailLabelsMessage(21), gmailLabelsMessage(22)],
            'pending',
        );
        $projector->projectThread(11);

        expect($outbox->items)->toHaveCount(2)
            ->and($outbox->items[2]['projection']->messageId)->toBe(22)
            ->and($outbox->items[2]['projection']->categoryIds)->toBe([4])
            ->and($outbox->items[2]['projection']->decision->decisionId)->toBe($decision->decisionId);
    });

    it('category_label_outbox_retries_without_rolling_back_classification', function () {
        $decision = gmailLabelsDecision(39, DecisionScope::THREAD, categoryIds: [4]);
        $snapshot = gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)]);
        $source = new GmailLabelsFakeDecisionSource($snapshot);
        $outbox = new GmailLabelsFakeOutbox;
        gmailLabelsProjector($source, $outbox, new GmailLabelsFakeDispatcher)->projectThread(11);

        $gateway = new GmailLabelsFakeProjectionGateway;
        $gateway->failNext = true;
        $consumer = new ConsumeCategoryLabelIntent(
            $outbox,
            new GmailLabelsFakeMappings([4 => new CategoryLabelMapping(4, 'Categoria', 'Label_4')]),
            $gateway,
            $source,
            new MessageLabelProjectionResolver,
        );

        expect(fn () => $consumer->consume(1))->toThrow(ProjectionDeliveryException::class)
            ->and($outbox->items[1]['status'])->toBe('retryable')
            ->and($source->snapshot?->latestOfficialThreadDecision)->toBe($decision)
            ->and($gateway->reconciliations)->toBe([]);
    });

    it('descarta antes do Gmail uma intent cuja decisão oficial ficou inconclusive', function () {
        $decision = gmailLabelsDecision(44, DecisionScope::THREAD, categoryIds: [4]);
        $source = new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)]));
        $outbox = new GmailLabelsFakeOutbox;
        gmailLabelsProjector($source, $outbox, new GmailLabelsFakeDispatcher)->projectThread(11);
        $source->snapshot = gmailLabelsThreadSnapshot(
            gmailLabelsDecision(45, DecisionScope::THREAD, OfficialDecisionState::INCONCLUSIVE, []),
            [gmailLabelsMessage(21)],
            'inconclusive',
        );
        $gateway = new GmailLabelsFakeProjectionGateway;
        $consumer = new ConsumeCategoryLabelIntent(
            $outbox,
            new GmailLabelsFakeMappings([4 => new CategoryLabelMapping(4, 'Categoria', 'Label_4')]),
            $gateway,
            $source,
            new MessageLabelProjectionResolver,
        );

        $consumer->consume(1);

        expect($outbox->items[1]['status'])->toBe('superseded')
            ->and($gateway->reconciliations)->toBe([])
            ->and($gateway->ensureCalls)->toBe(0);
    });

    it('revalida a decisão após garantir Label antes de associá-la à Message', function () {
        $decision = gmailLabelsDecision(46, DecisionScope::THREAD, categoryIds: [4]);
        $source = new GmailLabelsFakeDecisionSource(gmailLabelsThreadSnapshot($decision, [gmailLabelsMessage(21)]));
        $outbox = new GmailLabelsFakeOutbox;
        gmailLabelsProjector($source, $outbox, new GmailLabelsFakeDispatcher)->projectThread(11);
        $gateway = new GmailLabelsFakeProjectionGateway;
        $gateway->afterEnsure = function () use ($source): void {
            $source->snapshot = gmailLabelsThreadSnapshot(
                gmailLabelsDecision(47, DecisionScope::THREAD, OfficialDecisionState::INCONCLUSIVE, []),
                [gmailLabelsMessage(21)],
                'inconclusive',
            );
        };
        $consumer = new ConsumeCategoryLabelIntent(
            $outbox,
            new GmailLabelsFakeMappings(names: [4 => 'Categoria']),
            $gateway,
            $source,
            new MessageLabelProjectionResolver,
        );

        $consumer->consume(1);

        expect($gateway->ensureCalls)->toBe(1)
            ->and($gateway->reconciliations)->toBe([])
            ->and($outbox->items[1]['status'])->toBe('superseded');
    });

    it('rejeita uma decisão cuja autoria não seja Revisão humana', function () {
        expect(fn () => new OfficialDecision(
            decisionId: 40,
            reviewId: 140,
            threadId: 11,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::CATEGORIZED,
            categoryIds: [4],
            decisionAuthor: 'jev',
        ))->toThrow(InvalidArgumentException::class);
    });
});
