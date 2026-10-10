<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Services;

use InvalidArgumentException;

final class GmailOAuthScopePolicy
{
    public const MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    public const LABELS = 'https://www.googleapis.com/auth/gmail.labels';

    public const FULL_MAILBOX = 'https://mail.google.com/';

    /** @return list<string> */
    public function requiredScopes(): array
    {
        return [self::MODIFY, self::LABELS];
    }

    /** @param list<string> $grantedScopes */
    public function assertExactScopes(array $grantedScopes): void
    {
        $normalized = array_values(array_unique($grantedScopes));
        sort($normalized);

        $required = $this->requiredScopes();
        sort($required);

        if ($normalized !== $required || in_array(self::FULL_MAILBOX, $normalized, true)) {
            throw new InvalidArgumentException('Gmail OAuth must grant only gmail.modify and gmail.labels.');
        }
    }
}
