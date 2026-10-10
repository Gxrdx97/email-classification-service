<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;

final readonly class CaptureBatch
{
    /**
     * @param  list<CapturedMessage>  $messages
     * @param  list<string>  $touchedThreadIds  Threads alteradas no sync; somente o batch final transporta esta lista.
     * @param  null|string  $cursorAfter  null means this page is durable but is not the final page of the sync.
     */
    public function __construct(
        public int $accountId,
        public SyncMode $mode,
        public ?string $expectedCursor,
        public ?string $cursorAfter,
        public array $messages,
        public array $touchedThreadIds = [],
    ) {
        if ($accountId < 1) {
            throw new InvalidMailboxData('A conta Gmail é inválida.');
        }

        foreach ([$expectedCursor, $cursorAfter] as $cursor) {
            if ($cursor !== null && ! preg_match('/^\d+$/', $cursor)) {
                throw new InvalidMailboxData('O Cursor do Gmail é inválido.');
            }
        }

        $ids = [];
        foreach ($messages as $message) {
            if (! $message instanceof CapturedMessage) {
                throw new InvalidMailboxData('O lote de captura contém um item inválido.');
            }

            $id = $message->canonical->providerMessageId;
            if (isset($ids[$id])) {
                throw new InvalidMailboxData('O lote de captura contém identidades duplicadas.');
            }
            $ids[$id] = true;
        }

        if (! array_is_list($touchedThreadIds)) {
            throw new InvalidMailboxData('A lista de Threads alteradas é inválida.');
        }

        $threadIds = [];
        foreach ($touchedThreadIds as $threadId) {
            if (! is_string($threadId) || trim($threadId) === '') {
                throw new InvalidMailboxData('A lista de Threads alteradas contém uma identidade inválida.');
            }

            $key = 'thread:'.$threadId;
            if (isset($threadIds[$key])) {
                throw new InvalidMailboxData('A lista de Threads alteradas contém identidades duplicadas.');
            }

            $threadIds[$key] = true;
        }
    }
}
