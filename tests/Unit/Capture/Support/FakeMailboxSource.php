<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\MailboxSource;
use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\HistoryPage;
use App\Domain\Capture\Data\MessagePage;
use App\Domain\Capture\Data\ProviderMessage;
use App\Domain\Capture\Exceptions\HistoryExpired;

final class FakeMailboxSource implements MailboxSource
{
    /** @var list<MessagePage> */
    public array $messagePages = [];

    /** @var list<HistoryPage> */
    public array $historyPages = [];

    /** @var array<string, list<ProviderMessage>> */
    public array $threads = [];

    /** @var list<string> */
    public array $events = [];

    public bool $expireHistoryOnce = false;

    /** @var array<string, int> */
    private array $messageTokenIndexes = [];

    /** @var array<string, int> */
    private array $historyTokenIndexes = [];

    public function __construct(public GmailMailboxProfile $mailboxProfile) {}

    public function profile(): GmailMailboxProfile
    {
        $this->events[] = 'profile';

        return $this->mailboxProfile;
    }

    public function listMessages(?string $pageToken): MessagePage
    {
        $this->events[] = 'messages.list:'.($pageToken ?? 'first');
        $index = $pageToken === null ? 0 : ($this->messageTokenIndexes[$pageToken] ?? count($this->messagePages));
        $page = $this->messagePages[$index] ?? new MessagePage([], null);
        if ($page->nextPageToken !== null) {
            $this->messageTokenIndexes[$page->nextPageToken] = $index + 1;
        }

        return $page;
    }

    public function getThreadMessages(string $providerThreadId): array
    {
        $this->events[] = 'threads.get:'.$providerThreadId;

        return $this->threads[$providerThreadId] ?? [];
    }

    public function listHistory(string $startHistoryId, ?string $pageToken): HistoryPage
    {
        $this->events[] = 'history.list:'.($pageToken ?? 'first');
        if ($this->expireHistoryOnce) {
            $this->expireHistoryOnce = false;
            throw new HistoryExpired('expired');
        }

        $index = $pageToken === null ? 0 : ($this->historyTokenIndexes[$pageToken] ?? count($this->historyPages));
        $page = $this->historyPages[$index] ?? new HistoryPage([], $startHistoryId, null);
        if ($page->nextPageToken !== null) {
            $this->historyTokenIndexes[$page->nextPageToken] = $index + 1;
        }

        return $page;
    }
}
