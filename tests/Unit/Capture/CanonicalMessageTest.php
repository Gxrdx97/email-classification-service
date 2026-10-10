<?php

declare(strict_types=1);

use App\Domain\Capture\Data\CanonicalMessage;
use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CapturedMessage;
use App\Domain\Capture\Data\MimePart;
use App\Domain\Capture\Data\ProviderMessage;
use App\Domain\Capture\Data\SyncMode;
use App\Domain\Capture\Services\CanonicalMessageFactory;
use App\Domain\Capture\Services\DeterministicEnricher;
use App\Domain\Capture\Services\MessageVersionPolicy;
use Tests\Unit\Capture\Support\CaptureFixtures;
use Tests\Unit\Capture\Support\FakeCaptureStore;

describe('Captura canônica e enriquecimento determinístico', function () {
    it('labels_only_change_does_not_create_content_version', function () {
        $factory = new CanonicalMessageFactory;
        $original = $factory->fromProvider(CaptureFixtures::message(labels: ['INBOX']));
        $updatedLabels = $factory->fromProvider(CaptureFixtures::message(labels: ['SENT', 'CATEGORY/Team']));
        $enricher = new DeterministicEnricher;
        $store = new FakeCaptureStore;
        $store->commitBatch(new CaptureBatch(
            1,
            SyncMode::Initial,
            null,
            null,
            [new CapturedMessage($original, $enricher->enrich($original, 'owner@example.test', 1))],
        ));
        $store->commitBatch(new CaptureBatch(
            1,
            SyncMode::Incremental,
            null,
            '101',
            [new CapturedMessage($updatedLabels, $enricher->enrich($updatedLabels, 'owner@example.test', 1))],
        ));

        expect($updatedLabels->contentHash)->toBe($original->contentHash)
            ->and($updatedLabels->labelIds)->toBe(['SENT', 'CATEGORY/Team'])
            ->and((new MessageVersionPolicy)->shouldAppend($updatedLabels, [$original->contentHash]))->toBeFalse()
            ->and($store->messages['1|message-1']['hashes'])->toHaveCount(1)
            ->and($store->messages['1|message-1']['labels'])->toBe(['SENT', 'CATEGORY/Team']);
    });

    it('content_change_preserves_prior_message_version', function () {
        $factory = new CanonicalMessageFactory;
        $first = $factory->fromProvider(CaptureFixtures::message(text: 'Primeira versão da mensagem.'));
        $changed = $factory->fromProvider(CaptureFixtures::message(text: 'Segunda versão da mensagem.'));
        $enricher = new DeterministicEnricher;
        $store = new FakeCaptureStore;
        $store->commitBatch(new CaptureBatch(
            1,
            SyncMode::Initial,
            null,
            null,
            [new CapturedMessage($first, $enricher->enrich($first, 'owner@example.test', 1))],
        ));
        $store->commitBatch(new CaptureBatch(
            1,
            SyncMode::Full,
            null,
            '101',
            [new CapturedMessage($changed, $enricher->enrich($changed, 'owner@example.test', 1))],
        ));

        expect($changed->contentHash)->not->toBe($first->contentHash)
            ->and((new MessageVersionPolicy)->shouldAppend($changed, [$first->contentHash]))->toBeTrue()
            ->and((new MessageVersionPolicy)->shouldAppend($first, [$first->contentHash, $changed->contentHash]))->toBeFalse()
            ->and($store->messages['1|message-1']['hashes'])->toBe([$first->contentHash, $changed->contentHash]);
    });

    it('attachment_content_never_crosses_metadata_boundary', function () {
        $plainBody = 'Esta mensagem não é urgente e obrigado pela ajuda.';
        $providerMessage = new ProviderMessage(
            providerMessageId: 'synthetic-with-attachment',
            providerThreadId: 'thread-attachment',
            labelIds: ['SENT'],
            headers: [
                'from' => ['Owner <OWNER@example.test>'],
                'to' => ['Recipient <recipient@example.test>'],
                'subject' => ['Fixture sintética'],
                'message-id' => ['<one@example.test>'],
                'in-reply-to' => ['<prior@example.test>'],
                'references' => ['<root@example.test> <prior@example.test>'],
            ],
            internalDate: null,
            payload: new MimePart(
                mimeType: 'multipart/mixed',
                partId: 'root',
                filename: null,
                headers: [],
                bodyData: null,
                attachmentId: null,
                size: 0,
                parts: [
                    new MimePart(
                        mimeType: 'text/plain',
                        partId: 'text',
                        filename: null,
                        headers: [],
                        bodyData: rtrim(strtr(base64_encode($plainBody), '+/', '-_'), '='),
                        attachmentId: null,
                        size: strlen($plainBody),
                    ),
                    new MimePart(
                        mimeType: 'application/pdf',
                        partId: 'attachment-1',
                        filename: 'quarterly.pdf',
                        headers: ['content-disposition' => 'attachment; filename="quarterly.pdf"'],
                        bodyData: 'U0VDUkVU',
                        attachmentId: null,
                        size: 6,
                    ),
                ],
            ),
        );

        $canonical = (new CanonicalMessageFactory)->fromProvider($providerMessage);
        $enriched = (new DeterministicEnricher)->enrich($canonical, 'owner@example.test', 2);

        expect($canonical->attachments)->toHaveCount(1)
            ->and($canonical->attachments[0]->fileName)->toBe('quarterly.pdf')
            ->and($canonical->attachments[0]->mediaType)->toBe('application/pdf')
            ->and($canonical->attachments[0]->sizeBytes)->toBe(6)
            ->and($canonical->rawBodyText)->toBe($plainBody)
            ->and($enriched->direction)->toBe('outbound')
            ->and($enriched->languageCode)->toBe('pt')
            ->and($enriched->threadPosition)->toBe(2)
            ->and($enriched->participants[0]->normalizedEmail)->toBe('owner@example.test')
            ->and($enriched->deterministicFacts['message_id'])->toBe('<one@example.test>')
            ->and(json_encode([$canonical, $enriched], JSON_THROW_ON_ERROR))->not->toContain('U0VDUkVU')
            ->and(json_encode([$canonical, $enriched], JSON_THROW_ON_ERROR))->not->toContain('SECRET');
    });

    it('enrichment_persists_required_deterministic_facts', function () {
        $providerMessage = CaptureFixtures::message(
            text: 'Esta mensagem não é urgente e obrigado pela resposta.',
            headers: [
                'from' => ['Remetente <sender@example.test>'],
                'to' => ['Destinatário <owner@example.test>'],
                'cc' => ['Equipe <team@example.test>'],
                'bcc' => [],
                'subject' => ['Mensagem sintética'],
                'message-id' => ['<message@example.test>'],
                'in-reply-to' => ['<prior@example.test>'],
                'references' => ['<root@example.test> <prior@example.test>'],
            ],
        );
        $canonical = (new CanonicalMessageFactory)->fromProvider($providerMessage);
        $enriched = (new DeterministicEnricher)->enrich($canonical, 'owner@example.test', 3);

        expect($enriched->normalizedText)->toBe('Esta mensagem não é urgente e obrigado pela resposta.')
            ->and($enriched->participants)->toHaveCount(3)
            ->and($enriched->direction)->toBe('inbound')
            ->and($enriched->languageCode)->toBe('pt')
            ->and($enriched->threadPosition)->toBe(3)
            ->and($enriched->deterministicFacts['message_id'])->toBe('<message@example.test>')
            ->and($enriched->deterministicFacts['in_reply_to'])->toBe('<prior@example.test>')
            ->and($enriched->deterministicFacts['references'])->toBe(['<root@example.test>', '<prior@example.test>'])
            ->and($enriched->extractorName)->toBe(DeterministicEnricher::EXTRACTOR_NAME)
            ->and($enriched->extractorVersion)->toBe(DeterministicEnricher::EXTRACTOR_VERSION);
    });

    it('html_body_is_converted_and_quoted_replies_and_signatures_are_removed', function () {
        $message = new CanonicalMessage(
            providerMessageId: 'html-message',
            providerThreadId: 'html-thread',
            subject: 'HTML synthetic',
            rawBodyText: null,
            rawBodyHtml: '<p>Olá, esta mensagem não é urgente e obrigado.</p><div class="gmail_quote">Quoted PRIVATE</div><p>fim</p>',
            providerInternalDate: null,
            headers: ['from' => ['sender@example.test']],
            labelIds: [],
            attachments: [],
        );

        $enriched = (new DeterministicEnricher)->enrich($message, 'owner@example.test', 1);

        expect($enriched->normalizedText)->toContain('Olá, esta mensagem não é urgente')
            ->and($enriched->normalizedText)->not->toContain('Quoted PRIVATE')
            ->and($enriched->normalizedText)->not->toContain('fim')
            ->and($enriched->deterministicFacts['quoted_reply_stripped'])->toBeTrue();
    });

    it('strips_a_signature_with_a_versioned_deterministic_fact', function () {
        $message = new CanonicalMessage(
            providerMessageId: 'signature-message',
            providerThreadId: 'signature-thread',
            subject: null,
            rawBodyText: "Esta mensagem não é urgente e obrigado.\n--\nAssinatura sintética",
            rawBodyHtml: null,
            providerInternalDate: null,
            headers: [],
            labelIds: [],
            attachments: [],
        );

        $enriched = (new DeterministicEnricher)->enrich($message, 'owner@example.test', 1);

        expect($enriched->normalizedText)->not->toContain('Assinatura sintética')
            ->and($enriched->deterministicFacts['signature_detected'])->toBeTrue()
            ->and($enriched->extractorName)->toBe(DeterministicEnricher::EXTRACTOR_NAME)
            ->and($enriched->extractorVersion)->toBe(DeterministicEnricher::EXTRACTOR_VERSION);
    });
});
