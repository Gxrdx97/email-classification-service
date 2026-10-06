<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels\Contracts;

use App\Domain\GmailLabels\Data\GmailLabelDefinition;

interface GmailLabelApi
{
    /** @return list<GmailLabelDefinition> */
    public function listLabels(): array;

    public function createLabel(string $name): GmailLabelDefinition;

    /** @return list<string> */
    public function messageLabelIds(string $gmailMessageId): array;

    /** @param list<string> $addLabelIds
     * @param  list<string>  $removeLabelIds
     */
    public function modifyMessageLabels(string $gmailMessageId, array $addLabelIds, array $removeLabelIds): void;
}
