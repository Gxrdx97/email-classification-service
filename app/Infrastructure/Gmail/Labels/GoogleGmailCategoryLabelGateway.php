<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels;

use App\Domain\GmailLabels\Contracts\CategoryLabelGateway;
use App\Domain\GmailLabels\Contracts\CategoryLabelMappingRepository;
use App\Domain\GmailLabels\Data\CategoryLabelMapping;
use App\Domain\GmailLabels\Data\GmailLabelDefinition;
use App\Domain\GmailLabels\Services\GmailOAuthScopePolicy;
use App\Infrastructure\Gmail\Labels\Contracts\GmailLabelApi;
use App\Infrastructure\Gmail\Labels\Exceptions\GmailLabelApiFailure;
use InvalidArgumentException;
use Throwable;

final readonly class GoogleGmailCategoryLabelGateway implements CategoryLabelGateway
{
    /** @param list<string> $grantedScopes */
    public function __construct(
        private GmailLabelApi $api,
        private CategoryLabelMappingRepository $mappings,
        GmailOAuthScopePolicy $scopePolicy,
        array $grantedScopes,
    ) {
        $scopePolicy->assertExactScopes($grantedScopes);
    }

    public function ensureCategoryLabel(int $categoryId, string $displayName): CategoryLabelMapping
    {
        $existing = $this->mappings->find($categoryId);

        if ($existing !== null) {
            return $existing;
        }

        if ($categoryId < 1 || $displayName === '') {
            throw new InvalidArgumentException('Category Label identity is invalid.');
        }

        $matching = $this->findExactCustomLabel($displayName);

        if ($matching === null) {
            try {
                $matching = $this->api->createLabel($displayName);
            } catch (Throwable) {
                // A concurrent creator may have won; reuse only the exact custom label.
                $matching = $this->findExactCustomLabel($displayName);

                if ($matching === null) {
                    throw new GmailLabelApiFailure;
                }
            }
        }

        if (! $matching->custom || $matching->id === 'INBOX') {
            throw new InvalidArgumentException('Only custom Gmail Labels can be managed.');
        }

        return $this->mappings->saveIfMissing($categoryId, $displayName, $matching->id);
    }

    public function reconcileMessageAssociations(string $gmailMessageId, array $desiredManagedLabelIds): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,256}$/', $gmailMessageId) !== 1) {
            throw new InvalidArgumentException('Gmail Message ID is required.');
        }

        $managedById = [];

        foreach ($this->mappings->allManaged() as $mapping) {
            $managedById[$mapping->providerLabelId] = $mapping;
        }

        $desired = array_values(array_unique($desiredManagedLabelIds));

        foreach ($desired as $labelId) {
            if (! is_string($labelId) || $labelId === '' || $labelId === 'INBOX' || ! isset($managedById[$labelId])) {
                throw new InvalidArgumentException('Desired Gmail Label ID is not a managed custom Label.');
            }
        }

        $customIds = [];

        foreach ($this->api->listLabels() as $label) {
            if ($label->custom && $label->id !== 'INBOX') {
                $customIds[$label->id] = true;
            }
        }

        foreach ($desired as $labelId) {
            if (! isset($customIds[$labelId])) {
                throw new InvalidArgumentException('Desired Gmail Label ID is not a custom Label.');
            }
        }

        $managedCustomIds = array_intersect_key($managedById, $customIds);
        $current = array_values(array_unique($this->api->messageLabelIds($gmailMessageId)));
        $add = array_values(array_diff($desired, $current));
        $remove = array_values(array_diff(array_intersect($current, array_keys($managedCustomIds)), $desired));
        sort($add);
        sort($remove);

        if ($add !== [] || $remove !== []) {
            $this->api->modifyMessageLabels($gmailMessageId, $add, $remove);
        }
    }

    private function findExactCustomLabel(string $displayName): ?GmailLabelDefinition
    {
        $matches = array_values(array_filter(
            $this->api->listLabels(),
            static fn (GmailLabelDefinition $label): bool => $label->custom
                && $label->name === $displayName
                && $label->id !== 'INBOX',
        ));

        usort($matches, static fn ($left, $right): int => $left->id <=> $right->id);

        return $matches[0] ?? null;
    }
}
