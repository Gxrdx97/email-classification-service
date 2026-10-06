<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Contracts\CaptureStore;
use App\Domain\Capture\Contracts\MailboxSource;
use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CaptureCommit;
use App\Domain\Capture\Data\CapturedMessage;
use App\Domain\Capture\Data\MessageReference;
use App\Domain\Capture\Data\ProviderMessage;
use App\Domain\Capture\Data\SyncMode;
use App\Domain\Capture\Data\SyncResult;
use App\Domain\Capture\Exceptions\CaptureFailure;
use App\Domain\Capture\Exceptions\HistoryExpired;
use App\Domain\Capture\Exceptions\InvalidMailboxData;

final readonly class SyncMailbox
{
    public function __construct(
        private MailboxSource $mailbox,
        private CaptureStore $store,
        private CanonicalMessageFactory $canonicalizer,
        private DeterministicEnricher $enricher,
        private int $accountId = 1,
    ) {}

    public function synchronize(?string $currentCursor = null, bool $forceFull = false): SyncResult
    {
        if ($currentCursor !== null && ! preg_match('/^\d+$/', $currentCursor)) {
            throw new InvalidMailboxData('O Cursor armazenado é inválido.');
        }

        if ($currentCursor === null || $forceFull) {
            return $this->fullSync($currentCursor, $currentCursor === null ? SyncMode::Initial : SyncMode::Full);
        }

        try {
            return $this->incrementalSync($currentCursor);
        } catch (HistoryExpired) {
            return $this->fullSync($currentCursor, SyncMode::ExpiredHistoryResync);
        }
    }

    private function fullSync(?string $expectedCursor, SyncMode $mode): SyncResult
    {
        $profile = $this->mailbox->profile();
        $this->assertCursorDoesNotGoBack($expectedCursor, $profile->historyId);

        $token = null;
        $seenTokens = [];
        $seenThreads = [];
        $touchedThreadIds = [];
        $capturedCount = 0;
        $newCount = 0;

        do {
            $page = $this->mailbox->listMessages($token);
            $threadIds = [];
            foreach ($page->messages as $reference) {
                if (! $reference instanceof MessageReference) {
                    throw new InvalidMailboxData('A página do Gmail contém uma referência inválida.');
                }
                if (trim($reference->providerThreadId) === '') {
                    throw new InvalidMailboxData('A página do Gmail contém uma Thread inválida.');
                }

                $threadIds['thread:'.$reference->providerThreadId] = $reference->providerThreadId;
            }

            $threadIds = array_values($threadIds);
            sort($threadIds, SORT_STRING);
            $batchMessages = [];
            foreach ($threadIds as $threadId) {
                $threadKey = 'thread:'.$threadId;
                $touchedThreadIds[$threadKey] = $threadId;

                if (isset($seenThreads[$threadKey])) {
                    continue;
                }
                $seenThreads[$threadKey] = true;
                foreach ($this->captureThread($threadId, $profile->emailAddress) as $capturedMessage) {
                    $batchMessages[] = $capturedMessage;
                }
            }

            $finalPage = $page->nextPageToken === null || $page->nextPageToken === '';
            $receipt = $this->commit(
                mode: $mode,
                expectedCursor: $expectedCursor,
                cursorAfter: $finalPage ? $profile->historyId : null,
                messages: $batchMessages,
                touchedThreadIds: $finalPage ? $this->sortedThreadIds($touchedThreadIds) : [],
            );
            $capturedCount += $receipt->capturedMessages;
            $newCount += $receipt->newMessages;

            if ($finalPage) {
                return new SyncResult($mode, $profile->historyId, $capturedCount, $newCount);
            }

            $token = $page->nextPageToken;
            $this->assertNextPageToken($token, $seenTokens);
        } while (true);
    }

    private function incrementalSync(string $currentCursor): SyncResult
    {
        $profile = $this->mailbox->profile();
        $token = null;
        $seenTokens = [];
        $capturedCount = 0;
        $newCount = 0;
        $nextCursor = null;
        $touchedThreadIds = [];

        do {
            $page = $this->mailbox->listHistory($currentCursor, $token);
            $this->assertCursorDoesNotGoBack($currentCursor, $page->historyId);
            $nextCursor = $page->historyId;

            $threadIds = [];
            foreach ($page->changedThreadIds as $threadId) {
                if (! is_string($threadId) || trim($threadId) === '') {
                    throw new InvalidMailboxData('O histórico do Gmail contém uma Thread inválida.');
                }

                $threadIds['thread:'.$threadId] = $threadId;
                $touchedThreadIds['thread:'.$threadId] = $threadId;
            }

            $threadIds = array_values($threadIds);
            sort($threadIds, SORT_STRING);
            $batchMessages = [];
            foreach ($threadIds as $threadId) {
                foreach ($this->captureThread($threadId, $profile->emailAddress) as $capturedMessage) {
                    $batchMessages[] = $capturedMessage;
                }
            }

            $finalPage = $page->nextPageToken === null || $page->nextPageToken === '';
            $receipt = $this->commit(
                mode: SyncMode::Incremental,
                expectedCursor: $currentCursor,
                cursorAfter: $finalPage ? $nextCursor : null,
                messages: $batchMessages,
                touchedThreadIds: $finalPage ? $this->sortedThreadIds($touchedThreadIds) : [],
            );
            $capturedCount += $receipt->capturedMessages;
            $newCount += $receipt->newMessages;

            if ($finalPage) {
                return new SyncResult(SyncMode::Incremental, $nextCursor, $capturedCount, $newCount);
            }

            $token = $page->nextPageToken;
            $this->assertNextPageToken($token, $seenTokens);
        } while (true);
    }

    /** @return list<CapturedMessage> */
    private function captureThread(string $threadId, string $mailboxAddress): array
    {
        $messages = $this->mailbox->getThreadMessages($threadId);
        foreach ($messages as $message) {
            if (! $message instanceof ProviderMessage || $message->providerThreadId !== $threadId) {
                throw new InvalidMailboxData('O Gmail retornou uma Message fora da Thread solicitada.');
            }
        }

        usort($messages, static function (ProviderMessage $left, ProviderMessage $right): int {
            $leftTimestamp = $left->internalDate?->getTimestamp() ?? PHP_INT_MIN;
            $rightTimestamp = $right->internalDate?->getTimestamp() ?? PHP_INT_MIN;

            return ($leftTimestamp <=> $rightTimestamp)
                ?: strcmp($left->internalDate?->format('u') ?? '', $right->internalDate?->format('u') ?? '')
                ?: strcmp($left->providerMessageId, $right->providerMessageId);
        });

        $captured = [];
        foreach ($messages as $index => $providerMessage) {
            $canonical = $this->canonicalizer->fromProvider($providerMessage);
            $captured[] = new CapturedMessage(
                canonical: $canonical,
                enrichment: $this->enricher->enrich($canonical, $mailboxAddress, $index + 1),
            );
        }

        return $captured;
    }

    /** @param list<CapturedMessage> $messages */
    private function commit(
        SyncMode $mode,
        ?string $expectedCursor,
        ?string $cursorAfter,
        array $messages,
        array $touchedThreadIds = [],
    ): CaptureCommit {
        $batch = new CaptureBatch(
            accountId: $this->accountId,
            mode: $mode,
            expectedCursor: $expectedCursor,
            cursorAfter: $cursorAfter,
            messages: $messages,
            touchedThreadIds: $touchedThreadIds,
        );
        $receipt = $this->store->commitBatch($batch);
        $expectedCommittedCursor = $cursorAfter ?? $expectedCursor;

        if ($receipt->committedCursor !== $expectedCommittedCursor) {
            throw new CaptureFailure('A persistência da Captura não confirmou o Cursor esperado.');
        }

        return $receipt;
    }

    /** @param array<string, string> $threadIds
     * @return list<string>
     */
    private function sortedThreadIds(array $threadIds): array
    {
        $ids = array_values($threadIds);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param array<string, true> $seenTokens */
    private function assertNextPageToken(?string $token, array &$seenTokens): void
    {
        if ($token === null || $token === '' || isset($seenTokens[$token])) {
            throw new CaptureFailure('A paginação do Gmail não avançou.');
        }

        $seenTokens[$token] = true;
    }

    private function assertCursorDoesNotGoBack(?string $current, string $next): void
    {
        if ($current === null) {
            return;
        }

        $currentNormalized = ltrim($current, '0') ?: '0';
        $nextNormalized = ltrim($next, '0') ?: '0';
        if (
            strlen($nextNormalized) < strlen($currentNormalized)
            || (strlen($nextNormalized) === strlen($currentNormalized) && strcmp($nextNormalized, $currentNormalized) < 0)
        ) {
            throw new InvalidMailboxData('O Gmail retornou um Cursor anterior ao já persistido.');
        }
    }
}
