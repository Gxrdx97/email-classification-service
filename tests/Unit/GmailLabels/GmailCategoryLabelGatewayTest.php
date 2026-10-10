<?php

declare(strict_types=1);

use App\Domain\GmailLabels\Contracts\CategoryLabelMappingRepository;
use App\Domain\GmailLabels\Data\CategoryLabelMapping;
use App\Domain\GmailLabels\Data\GmailLabelDefinition;
use App\Domain\GmailLabels\Services\GmailOAuthScopePolicy;
use App\Infrastructure\Gmail\Labels\Contracts\GmailLabelApi;
use App\Infrastructure\Gmail\Labels\GoogleGmailCategoryLabelGateway;

final class GmailLabelsMemoryMappingRepository implements CategoryLabelMappingRepository
{
    /** @var array<int, CategoryLabelMapping> */
    public array $stored = [];

    /** @param array<int, string> $names */
    public function __construct(public array $names = []) {}

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

final class GmailLabelsFakeApi implements GmailLabelApi
{
    /** @var list<GmailLabelDefinition> */
    public array $labels = [];

    /** @var array<string, list<string>> */
    public array $messageLabels = [];

    /** @var list<array{message_id: string, add: list<string>, remove: list<string>}> */
    public array $modifications = [];

    public int $createCalls = 0;

    public bool $failModify = false;

    public function listLabels(): array
    {
        return $this->labels;
    }

    public function createLabel(string $name): GmailLabelDefinition
    {
        $this->createCalls++;
        $label = new GmailLabelDefinition('Label_created_'.$this->createCalls, $name, true);
        $this->labels[] = $label;

        return $label;
    }

    public function messageLabelIds(string $gmailMessageId): array
    {
        return $this->messageLabels[$gmailMessageId] ?? [];
    }

    public function modifyMessageLabels(string $gmailMessageId, array $addLabelIds, array $removeLabelIds): void
    {
        if ($this->failModify) {
            throw new RuntimeException('Synthetic provider failure.');
        }

        $this->modifications[] = [
            'message_id' => $gmailMessageId,
            'add' => $addLabelIds,
            'remove' => $removeLabelIds,
        ];
        $current = $this->messageLabelIds($gmailMessageId);
        $this->messageLabels[$gmailMessageId] = array_values(array_unique(array_merge(
            array_values(array_diff($current, $removeLabelIds)),
            $addLabelIds,
        )));
    }
}

function gmailLabelsGateway(
    GmailLabelsFakeApi $api,
    GmailLabelsMemoryMappingRepository $mappings,
    ?array $grantedScopes = null,
): GoogleGmailCategoryLabelGateway {
    $policy = new GmailOAuthScopePolicy;

    return new GoogleGmailCategoryLabelGateway(
        $api,
        $mappings,
        $policy,
        $grantedScopes ?? $policy->requiredScopes(),
    );
}

describe('gateway restrito de Labels de Categoria do Gmail', function () {
    it('usa exatamente os scopes gmail.modify e gmail.labels', function () {
        $policy = new GmailOAuthScopePolicy;
        $expected = [
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.labels',
        ];

        $policy->assertExactScopes($expected);

        expect($policy->requiredScopes())->toBe($expected)
            ->and(fn () => $policy->assertExactScopes([...$expected, 'https://www.googleapis.com/auth/gmail.readonly']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('category_label_mapping_reuses_or_creates_exact_name', function () {
        $api = new GmailLabelsFakeApi;
        $api->labels = [
            new GmailLabelDefinition('Label_exact', 'Projetos', true),
            new GmailLabelDefinition('Label_case', 'projetos', true),
        ];
        $mappings = new GmailLabelsMemoryMappingRepository;
        $gateway = gmailLabelsGateway($api, $mappings);

        $first = $gateway->ensureCategoryLabel(7, 'Projetos');
        $api->labels = [];
        $second = $gateway->ensureCategoryLabel(7, 'Nome alterado');
        $created = $gateway->ensureCategoryLabel(8, 'Acompanhamento');

        expect($first->providerLabelId)->toBe('Label_exact')
            ->and($second)->toBe($first)
            ->and($created->providerLabelId)->toBe('Label_created_1')
            ->and($api->createCalls)->toBe(1)
            ->and($mappings->find(7)?->displayName)->toBe('Projetos');
    });

    it('cria uma Label customizada quando só existe definição system com o mesmo nome', function () {
        $api = new GmailLabelsFakeApi;
        $api->labels = [new GmailLabelDefinition('CATEGORY_PERSONAL', 'Pessoal', false)];
        $gateway = gmailLabelsGateway($api, new GmailLabelsMemoryMappingRepository);

        $mapping = $gateway->ensureCategoryLabel(2, 'Pessoal');

        expect($mapping->providerLabelId)->toBe('Label_created_1')
            ->and($mapping->providerLabelId)->not->toBe('CATEGORY_PERSONAL');
    });

    it('category_label_reconcile_restores_and_replaces_managed_associations_only', function () {
        $api = new GmailLabelsFakeApi;
        $api->labels = [
            new GmailLabelDefinition('INBOX', 'INBOX', false),
            new GmailLabelDefinition('Label_old', 'Antiga', true),
            new GmailLabelDefinition('Label_new', 'Nova', true),
            new GmailLabelDefinition('Label_other', 'Pessoal', true),
            new GmailLabelDefinition('STARRED', 'STARRED', false),
        ];
        $api->messageLabels['gmail-message-1'] = ['INBOX', 'Label_old', 'Label_other', 'STARRED'];
        $mappings = new GmailLabelsMemoryMappingRepository;
        $mappings->stored = [
            1 => new CategoryLabelMapping(1, 'Antiga', 'Label_old'),
            2 => new CategoryLabelMapping(2, 'Nova', 'Label_new'),
        ];
        $gateway = gmailLabelsGateway($api, $mappings);

        $gateway->reconcileMessageAssociations('gmail-message-1', ['Label_new']);

        expect($api->modifications)->toBe([[
            'message_id' => 'gmail-message-1',
            'add' => ['Label_new'],
            'remove' => ['Label_old'],
        ]])
            ->and($api->messageLabels['gmail-message-1'])->toContain('INBOX', 'Label_other', 'STARRED', 'Label_new')
            ->and($api->messageLabels['gmail-message-1'])->not->toContain('Label_old');
    });

    it('category_label_writer_rejects_unmanaged_scope_and_operations', function () {
        $policy = new GmailOAuthScopePolicy;
        $api = new GmailLabelsFakeApi;
        $api->labels = [
            new GmailLabelDefinition('INBOX', 'INBOX', false),
            new GmailLabelDefinition('CATEGORY_PERSONAL', 'Pessoal', false),
            new GmailLabelDefinition('Label_managed', 'Gerida', true),
        ];
        $mappings = new GmailLabelsMemoryMappingRepository;
        $mappings->stored = [
            1 => new CategoryLabelMapping(1, 'Gerida', 'Label_managed'),
            2 => new CategoryLabelMapping(2, 'Pessoal', 'CATEGORY_PERSONAL'),
        ];

        expect(fn () => gmailLabelsGateway(
            $api,
            $mappings,
            [...$policy->requiredScopes(), GmailOAuthScopePolicy::FULL_MAILBOX],
        ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => gmailLabelsGateway($api, $mappings)->reconcileMessageAssociations('gmail-message-1', ['INBOX']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => gmailLabelsGateway($api, $mappings)->reconcileMessageAssociations('gmail-message-1', ['CATEGORY_PERSONAL']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => gmailLabelsGateway($api, $mappings)->reconcileMessageAssociations('gmail-message-1', ['Label_unmanaged']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => gmailLabelsGateway($api, $mappings)->reconcileMessageAssociations('not/a-gmail-id', ['Label_managed']))
            ->toThrow(InvalidArgumentException::class)
            ->and(method_exists(gmailLabelsGateway($api, $mappings), 'send'))->toBeFalse()
            ->and(method_exists(gmailLabelsGateway($api, $mappings), 'archive'))->toBeFalse()
            ->and(method_exists(gmailLabelsGateway($api, $mappings), 'delete'))->toBeFalse();
    });

    it('uncategorized_clears_only_managed_associations_and_skips_noop_modify', function () {
        $api = new GmailLabelsFakeApi;
        $api->labels = [
            new GmailLabelDefinition('INBOX', 'INBOX', false),
            new GmailLabelDefinition('Label_managed', 'Gerida', true),
            new GmailLabelDefinition('Label_other', 'Alheia', true),
        ];
        $api->messageLabels['gmail-message-2'] = ['INBOX', 'Label_managed', 'Label_other'];
        $mappings = new GmailLabelsMemoryMappingRepository;
        $mappings->stored[1] = new CategoryLabelMapping(1, 'Gerida', 'Label_managed');
        $gateway = gmailLabelsGateway($api, $mappings);

        $gateway->reconcileMessageAssociations('gmail-message-2', []);
        $gateway->reconcileMessageAssociations('gmail-message-2', []);

        expect($api->modifications)->toHaveCount(1)
            ->and($api->modifications[0]['add'])->toBe([])
            ->and($api->modifications[0]['remove'])->toBe(['Label_managed'])
            ->and($api->messageLabels['gmail-message-2'])->toContain('INBOX', 'Label_other');
    });
});
