<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\HistoryPage;
use App\Domain\Capture\Data\MessagePage;
use App\Domain\Capture\Data\ProviderMessage;

/** A porta de captura é somente leitura; não oferece operações de Labels nem de mensagens. */
interface MailboxSource
{
    public function profile(): GmailMailboxProfile;

    public function listMessages(?string $pageToken): MessagePage;

    /** @return list<ProviderMessage> Mensagens atuais da Thread, em qualquer ordem do provedor. */
    public function getThreadMessages(string $providerThreadId): array;

    public function listHistory(string $startHistoryId, ?string $pageToken): HistoryPage;
}
