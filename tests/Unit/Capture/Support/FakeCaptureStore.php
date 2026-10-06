<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\CaptureStore;
use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CaptureCommit;
use App\Domain\Capture\Exceptions\CaptureFailure;
use App\Domain\Capture\Services\MessageVersionPolicy;
use App\Domain\Capture\Services\ThreadCapturePolicy;

final class FakeCaptureStore implements CaptureStore
{
    public ?string $cursor = null;

    /** @var list<CaptureBatch> */
    public array $batches = [];

    /** @var list<string> */
    public array $events = [];

    /** @var array<string, array{thread: string, hashes: list<string>, labels: list<string>}> */
    public array $messages = [];

    /** @var array<string, array{state: string, official_id: ?int, labels: list<string>, reclassified: bool}> */
    public array $threads = [];

    public bool $failNextCommit = false;

    public function __construct(
        private readonly MessageVersionPolicy $versions = new MessageVersionPolicy,
        private readonly ThreadCapturePolicy $threadPolicy = new ThreadCapturePolicy,
    ) {}

    public function seedOfficialThread(string $providerThreadId, int $classificationId, array $inheritedLabels): void
    {
        $this->threads[$providerThreadId] = [
            'state' => 'categorized',
            'official_id' => $classificationId,
            'labels' => $inheritedLabels,
            'reclassified' => false,
        ];
    }

    public function commitBatch(CaptureBatch $batch): CaptureCommit
    {
        if ($batch->expectedCursor !== $this->cursor) {
            throw new CaptureFailure('Cursor conflict');
        }
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            throw new CaptureFailure('Persistence failed');
        }

        $this->batches[] = $batch;
        $newMessages = 0;

        foreach ($batch->messages as $captured) {
            $id = $batch->accountId.'|'.$captured->canonical->providerMessageId;
            $threadId = $captured->canonical->providerThreadId;
            $isNew = ! isset($this->messages[$id]);
            if ($isNew) {
                $newMessages++;
                $this->messages[$id] = ['thread' => $threadId, 'hashes' => [], 'labels' => []];
            }

            $record = $this->messages[$id];
            if ($this->versions->shouldAppend($captured->canonical, $record['hashes'])) {
                $record['hashes'][] = $captured->canonical->contentHash;
                $this->events[] = 'version:'.$captured->canonical->providerMessageId;
            }
            $record['labels'] = $captured->canonical->labelIds;
            $this->messages[$id] = $record;
            $this->events[] = 'persisted:'.$captured->canonical->providerMessageId;

            if ($isNew && isset($this->threads[$threadId]['official_id'])) {
                $thread = $this->threads[$threadId];
                $effect = $this->threadPolicy->onNewMessage($thread['official_id']);
                $this->threads[$threadId] = [
                    'state' => $effect->operationalState,
                    'official_id' => $effect->latestOfficialClassificationId,
                    'labels' => $effect->preserveInheritedLabels ? $thread['labels'] : [],
                    'reclassified' => $effect->startAutomaticReclassification,
                ];
            }
        }

        $this->events[] = 'batch-persisted';
        if ($batch->cursorAfter !== null) {
            $this->cursor = $batch->cursorAfter;
            $this->events[] = 'cursor:'.$batch->cursorAfter;
        }

        return new CaptureCommit($this->cursor, count($batch->messages), $newMessages);
    }
}
