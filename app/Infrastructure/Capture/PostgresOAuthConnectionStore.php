<?php

declare(strict_types=1);

namespace App\Infrastructure\Capture;

use App\Domain\Capture\Contracts\OAuthConnectionStore;
use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Data\OAuthConnection;
use App\Domain\Capture\Exceptions\OAuthFailure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class PostgresOAuthConnectionStore implements OAuthConnectionStore
{
    public function get(): ?OAuthConnection
    {
        try {
            $row = DB::selectOne(<<<'SQL'
SELECT gmail_user_id,
       email_address,
       to_json(granted_scopes)::text AS granted_scopes_json,
       encode(refresh_token_ciphertext, 'base64') AS refresh_token_ciphertext_base64,
       connected_at
FROM gmail_accounts
WHERE id = 1
SQL);
        } catch (QueryException) {
            throw new OAuthFailure('A conexão Gmail não pôde ser lida.');
        }

        if (
            $row === null
            || $row->gmail_user_id === null
            || $row->email_address === null
            || $row->refresh_token_ciphertext_base64 === null
            || $row->connected_at === null
        ) {
            return null;
        }

        try {
            $scopes = json_decode((string) $row->granted_scopes_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OAuthFailure('A conexão Gmail armazenada está inválida.');
        }

        if (! is_array($scopes) || ! array_is_list($scopes)) {
            throw new OAuthFailure('A conexão Gmail armazenada está inválida.');
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                throw new OAuthFailure('A conexão Gmail armazenada está inválida.');
            }
        }

        /** @var list<string> $scopes */
        GmailScopes::assertExact($scopes);

        $ciphertext = base64_decode((string) $row->refresh_token_ciphertext_base64, true);
        if ($ciphertext === false || $ciphertext === '') {
            throw new OAuthFailure('A credencial Gmail armazenada está inválida.');
        }

        try {
            $connectedAt = new DateTimeImmutable((string) $row->connected_at);
        } catch (\Exception) {
            throw new OAuthFailure('A conexão Gmail armazenada está inválida.');
        }

        return new OAuthConnection(
            gmailUserId: (string) $row->gmail_user_id,
            emailAddress: (string) $row->email_address,
            grantedScopes: $scopes,
            refreshTokenCiphertext: $ciphertext,
            connectedAt: $connectedAt,
        );
    }

    public function save(OAuthConnection $connection): void
    {
        GmailScopes::assertExact($connection->grantedScopes);
        if (
            trim($connection->gmailUserId) === ''
            || trim($connection->emailAddress) === ''
            || $connection->refreshTokenCiphertext() === ''
        ) {
            throw new OAuthFailure('A conexão Gmail não contém os dados essenciais.');
        }

        try {
            DB::statement(<<<'SQL'
INSERT INTO gmail_accounts (
    id,
    gmail_user_id,
    email_address,
    refresh_token_ciphertext,
    granted_scopes,
    connected_at,
    created_at,
    updated_at
)
VALUES (1, ?, ?, decode(?, 'base64'), ?::text[], ?, ?, ?)
ON CONFLICT (id) DO UPDATE SET
    gmail_user_id = EXCLUDED.gmail_user_id,
    email_address = EXCLUDED.email_address,
    refresh_token_ciphertext = EXCLUDED.refresh_token_ciphertext,
    granted_scopes = EXCLUDED.granted_scopes,
    connected_at = EXCLUDED.connected_at,
    updated_at = EXCLUDED.updated_at
SQL, [
                $connection->gmailUserId,
                $connection->emailAddress,
                base64_encode($connection->refreshTokenCiphertext()),
                $this->postgresTextArray($connection->grantedScopes),
                $connection->connectedAt,
                now(),
                now(),
            ]);
        } catch (QueryException) {
            throw new OAuthFailure('A conexão Gmail não pôde ser persistida.');
        }
    }

    public function delete(): void
    {
        try {
            DB::statement(<<<'SQL'
UPDATE gmail_accounts
SET gmail_user_id = NULL,
    email_address = NULL,
    refresh_token_ciphertext = NULL,
    granted_scopes = ARRAY[]::text[],
    connected_at = NULL,
    updated_at = ?
WHERE id = 1
SQL, [now()]);
        } catch (QueryException) {
            throw new OAuthFailure('A conexão Gmail não pôde ser removida.');
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function postgresTextArray(array $values): string
    {
        $quoted = array_map(
            static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        );

        return '{'.implode(',', $quoted).'}';
    }
}
