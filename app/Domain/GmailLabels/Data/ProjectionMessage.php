<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use App\Domain\GmailLabels\Enums\DecisionScope;
use InvalidArgumentException;

final readonly class ProjectionMessage
{
    public function __construct(
        public int $accountId,
        public int $messageId,
        public int $threadId,
        public string $gmailMessageId,
        public ?OfficialDecision $messageOverride = null,
    ) {
        if (
            $accountId < 1
            || $messageId < 1
            || $threadId < 1
            || preg_match('/^[A-Za-z0-9_-]{1,256}$/', $gmailMessageId) !== 1
        ) {
            throw new InvalidArgumentException('Projection message identifiers are invalid.');
        }

        if (
            $messageOverride !== null
            && ($messageOverride->scope !== DecisionScope::MESSAGE
                || $messageOverride->threadId !== $threadId
                || $messageOverride->targetMessageId !== $messageId)
        ) {
            throw new InvalidArgumentException('Message override does not target this Message.');
        }
    }
}
