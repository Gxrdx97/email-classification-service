<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use App\Domain\GmailLabels\Enums\DecisionScope;
use InvalidArgumentException;

final readonly class ThreadProjectionSnapshot
{
    /**
     * @param  list<ProjectionMessage>  $messages
     */
    public function __construct(
        public int $accountId,
        public int $threadId,
        public string $operationalState,
        public ?OfficialDecision $latestOfficialThreadDecision,
        public array $messages,
    ) {
        if ($accountId < 1 || $threadId < 1) {
            throw new InvalidArgumentException('Thread projection identifiers are invalid.');
        }

        if (! in_array($operationalState, ['pending', 'categorized', 'uncategorized', 'inconclusive', 'failed'], true)) {
            throw new InvalidArgumentException('Unknown Thread operational state.');
        }

        if (
            $latestOfficialThreadDecision !== null
            && ($latestOfficialThreadDecision->scope !== DecisionScope::THREAD
                || $latestOfficialThreadDecision->threadId !== $threadId)
        ) {
            throw new InvalidArgumentException('Latest Thread decision does not target this Thread.');
        }

        $messageIds = [];

        foreach ($messages as $message) {
            if (! $message instanceof ProjectionMessage
                || $message->accountId !== $accountId
                || $message->threadId !== $threadId) {
                throw new InvalidArgumentException('Thread snapshot contains a Message from another scope.');
            }

            if (isset($messageIds[$message->messageId])) {
                throw new InvalidArgumentException('Thread snapshot contains a duplicate Message.');
            }

            $messageIds[$message->messageId] = true;
        }
    }
}
