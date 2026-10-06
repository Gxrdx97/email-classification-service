<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\OAuthScopeMismatch;

final class GmailScopes
{
    public const MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    public const LABELS = 'https://www.googleapis.com/auth/gmail.labels';

    public const MAIL_GOOGLE_COM = 'https://mail.google.com/';

    /** @return list<string> */
    public static function required(): array
    {
        return [self::MODIFY, self::LABELS];
    }

    /** @param list<string> $grantedScopes */
    public static function assertExact(array $grantedScopes): void
    {
        foreach ($grantedScopes as $scope) {
            if (! is_string($scope)) {
                throw new OAuthScopeMismatch('O Gmail retornou um escopo inválido.');
            }
        }

        $grantedScopes = array_values(array_unique(array_filter(array_map('trim', $grantedScopes))));

        if (in_array(self::MAIL_GOOGLE_COM, $grantedScopes, true)) {
            throw new OAuthScopeMismatch('O Gmail concedeu um escopo não permitido.');
        }

        $expected = self::required();
        sort($expected);
        sort($grantedScopes);

        if ($grantedScopes !== $expected) {
            throw new OAuthScopeMismatch('O Gmail não concedeu exatamente os escopos exigidos.');
        }
    }
}
