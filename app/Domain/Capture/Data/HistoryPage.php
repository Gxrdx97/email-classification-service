<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;

final readonly class HistoryPage
{
    /**
     * @param  list<string>  $changedThreadIds
     */
    public function __construct(
        public array $changedThreadIds,
        public string $historyId,
        public ?string $nextPageToken,
    ) {
        if (! preg_match('/^\d+$/', $historyId)) {
            throw new InvalidMailboxData('O Gmail retornou um Cursor inválido.');
        }
    }
}
