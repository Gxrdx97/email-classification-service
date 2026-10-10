<?php

declare(strict_types=1);

use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\HistoryPage;
use App\Domain\Capture\Data\MessagePage;
use App\Domain\Capture\Data\MessageReference;
use App\Domain\Capture\Data\SyncMode;
use App\Domain\Capture\Exceptions\CaptureFailure;
use App\Domain\Capture\Exceptions\InvalidMailboxData;
use App\Domain\Capture\Services\CanonicalMessageFactory;
use App\Domain\Capture\Services\DeterministicEnricher;
use App\Domain\Capture\Services\SyncMailbox;
use Tests\Unit\Capture\Support\CaptureFixtures;
use Tests\Unit\Capture\Support\FakeCaptureStore;
use Tests\Unit\Capture\Support\FakeMailboxSource;

describe('sincronização e Captura canônica', function () {
    it('first_sync_persists_before_cursor', function () {
        $mailbox = new FakeMailboxSource(new GmailMailboxProfile('owner@example.test', '100'));
        $mailbox->messagePages = [
            new MessagePage([new MessageReference('message-t1', 't1')], 'page-2'),
            new MessagePage([new MessageReference('message-t2', 't2')], null),
        ];
        $mailbox->threads = [
            't1' => [CaptureFixtures::message('message-t1', 't1')],
            't2' => [CaptureFixtures::message('message-t2', 't2')],
        ];
        $store = new FakeCaptureStore;

        $result = syncMailbox($mailbox, $store)->synchronize();

        expect($result->mode)->toBe(SyncMode::Initial)
            ->and($result->cursor)->toBe('100')
            ->and($store->batches)->toHaveCount(2)
            ->and($store->batches[0]->cursorAfter)->toBeNull()
            ->and($store->batches[0]->touchedThreadIds)->toBe([])
            ->and($store->batches[1]->cursorAfter)->toBe('100')
            ->and($store->batches[1]->touchedThreadIds)->toBe(['t1', 't2'])
            ->and($store->cursor)->toBe('100')
            ->and(array_keys($store->messages))->toBe(['1|message-t1', '1|message-t2']);

        expect(array_search('cursor:100', $store->events, true))
            ->toBeGreaterThan(array_search('persisted:message-t2', $store->events, true));
    });

    it('incremental_sync_advances_cursor_after_persistence', function () {
        $mailbox = new FakeMailboxSource(new GmailMailboxProfile('owner@example.test', '220'));
        $mailbox->historyPages = [
            new HistoryPage(['t1'], '210', 'history-page-2'),
            new HistoryPage(['t1', 't2'], '220', null),
        ];
        $mailbox->threads = [
            't1' => [CaptureFixtures::message('message-t1', 't1')],
            't2' => [CaptureFixtures::message('message-t2', 't2')],
        ];
        $store = new FakeCaptureStore;
        $store->cursor = '200';

        $result = syncMailbox($mailbox, $store)->synchronize('200');

        expect($result->mode)->toBe(SyncMode::Incremental)
            ->and($result->cursor)->toBe('220')
            ->and($store->batches)->toHaveCount(2)
            ->and($store->batches[0]->expectedCursor)->toBe('200')
            ->and($store->batches[0]->cursorAfter)->toBeNull()
            ->and($store->batches[0]->touchedThreadIds)->toBe([])
            ->and($store->batches[1]->cursorAfter)->toBe('220')
            ->and($store->batches[1]->touchedThreadIds)->toBe(['t1', 't2'])
            ->and($store->cursor)->toBe('220');

        expect(array_search('cursor:220', $store->events, true))
            ->toBeGreaterThan(array_search('persisted:message-t2', $store->events, true));
    });

    it('rejects_invalid_touched_thread_id_lists', function () {
        $batch = static fn (array $threadIds): CaptureBatch => new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '10',
            cursorAfter: null,
            messages: [],
            touchedThreadIds: $threadIds,
        );

        expect($batch([])->touchedThreadIds)->toBe([])
            ->and(fn () => $batch(['']))
            ->toThrow(InvalidMailboxData::class)
            ->and(fn () => $batch(['thread-a', 'thread-a']))
            ->toThrow(InvalidMailboxData::class)
            ->and(fn () => $batch(['named' => 'thread-a']))
            ->toThrow(InvalidMailboxData::class);
    });

    it('cursor_remains_unchanged_if_capture_persistence_fails', function () {
        $mailbox = new FakeMailboxSource(new GmailMailboxProfile('owner@example.test', '220'));
        $mailbox->historyPages = [new HistoryPage(['t1'], '220', null)];
        $mailbox->threads = ['t1' => [CaptureFixtures::message('message-t1', 't1')]];
        $store = new FakeCaptureStore;
        $store->cursor = '200';
        $store->failNextCommit = true;

        expect(fn () => syncMailbox($mailbox, $store)->synchronize('200'))
            ->toThrow(CaptureFailure::class);
        expect($store->cursor)->toBe('200');
    });

    it('expired_history_runs_idempotent_full_resync', function () {
        $mailbox = new FakeMailboxSource(new GmailMailboxProfile('owner@example.test', '300'));
        $mailbox->expireHistoryOnce = true;
        $mailbox->messagePages = [new MessagePage([new MessageReference('message-t1', 't1')], null)];
        $mailbox->threads = ['t1' => [CaptureFixtures::message('message-t1', 't1')]];
        $store = new FakeCaptureStore;
        $store->cursor = '200';
        $sync = syncMailbox($mailbox, $store);

        $resync = $sync->synchronize('200');
        $retry = $sync->synchronize('300', forceFull: true);

        expect($resync->mode)->toBe(SyncMode::ExpiredHistoryResync)
            ->and($resync->cursor)->toBe('300')
            ->and($retry->cursor)->toBe('300')
            ->and($store->messages)->toHaveCount(1)
            ->and($store->messages['1|message-t1']['hashes'])->toHaveCount(1);
    });

    it('new_message_does_not_auto_reclassify_classified_thread', function () {
        $mailbox = new FakeMailboxSource(new GmailMailboxProfile('owner@example.test', '400'));
        $mailbox->messagePages = [new MessagePage([new MessageReference('new-message', 'classified-thread')], null)];
        $mailbox->threads = ['classified-thread' => [CaptureFixtures::message('new-message', 'classified-thread')]];
        $store = new FakeCaptureStore;
        $store->seedOfficialThread('classified-thread', 73, ['CATEGORY/Approved']);

        syncMailbox($mailbox, $store)->synchronize();

        expect($store->threads['classified-thread'])->toBe([
            'state' => 'pending',
            'official_id' => 73,
            'labels' => ['CATEGORY/Approved'],
            'reclassified' => false,
        ]);
    });
});

function syncMailbox(FakeMailboxSource $mailbox, FakeCaptureStore $store): SyncMailbox
{
    return new SyncMailbox($mailbox, $store, new CanonicalMessageFactory, new DeterministicEnricher);
}
