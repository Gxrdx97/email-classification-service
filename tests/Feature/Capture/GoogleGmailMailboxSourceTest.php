<?php

declare(strict_types=1);

use App\Domain\Capture\Data\AccessToken;
use App\Domain\Capture\Data\OAuthSecret;
use App\Domain\Capture\Exceptions\HistoryExpired;
use App\Domain\Capture\Services\CanonicalMessageFactory;
use App\Infrastructure\Gmail\ReadOnly\GoogleGmailMailboxSource;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

describe('adapter de leitura Gmail', function () {
    it('reads messages and keeps attachment payload bytes out of domain objects', function () {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'messages' => [['id' => 'm-1', 'threadId' => 't-1']],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 't-1',
                'messages' => [[
                    'id' => 'm-1',
                    'threadId' => 't-1',
                    'labelIds' => ['INBOX'],
                    'internalDate' => '1790856000000',
                    'payload' => [
                        'mimeType' => 'multipart/mixed',
                        'headers' => [
                            ['name' => 'Subject', 'value' => 'Synthetic attachment fixture'],
                            ['name' => 'From', 'value' => 'sender@example.test'],
                        ],
                        'parts' => [
                            [
                                'partId' => 'text-1',
                                'mimeType' => 'text/plain',
                                'body' => ['data' => rtrim(strtr(base64_encode('Synthetic body'), '+/', '-_'), '='), 'size' => 14],
                            ],
                            [
                                'partId' => 'text-attachment-1',
                                'mimeType' => 'text/plain',
                                'body' => [
                                    'data' => rtrim(strtr(base64_encode('Synthetic text attachment'), '+/', '-_'), '='),
                                    'attachmentId' => 'synthetic-text-attachment-id',
                                    'size' => 25,
                                ],
                            ],
                            [
                                'partId' => 'filename-text-attachment-1',
                                'filename' => 'synthetic.txt',
                                'mimeType' => 'text/plain',
                                'body' => [
                                    'data' => rtrim(strtr(base64_encode('Synthetic filename attachment'), '+/', '-_'), '='),
                                    'size' => 30,
                                ],
                            ],
                            [
                                'partId' => 'attachment-1',
                                'filename' => 'synthetic.pdf',
                                'mimeType' => 'application/pdf',
                                'body' => ['data' => 'U0VDUkVU', 'size' => 6],
                            ],
                            [
                                'partId' => 'disposition-attachment-1',
                                'mimeType' => 'text/html',
                                'headers' => [
                                    ['name' => 'Content-Disposition', 'value' => ' attachment; filename="synthetic.html"'],
                                ],
                                'body' => [
                                    'data' => rtrim(strtr(base64_encode('<p>Synthetic attached HTML</p>'), '+/', '-_'), '='),
                                    'size' => 29,
                                ],
                            ],
                            [
                                'partId' => 'inline-image-1',
                                'mimeType' => 'image/png',
                                'body' => ['data' => 'SU1BR0U=', 'size' => 5],
                            ],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $handler->push(Middleware::history($history));
        $client = new GoogleClient;
        $client->setHttpClient(new HttpClient(['handler' => $handler]));
        $source = new GoogleGmailMailboxSource(
            new AccessToken(new OAuthSecret('synthetic-access-token'), new DateTimeImmutable('+1 hour')),
            $client,
        );

        $page = $source->listMessages(null);
        $threadMessages = $source->getThreadMessages('t-1');
        $canonical = (new CanonicalMessageFactory)->fromProvider($threadMessages[0]);
        $mimeParts = $threadMessages[0]->payload->parts;

        expect($page->messages[0]->providerMessageId)->toBe('m-1')
            ->and($threadMessages)->toHaveCount(1)
            ->and($canonical->rawBodyText)->toBe('Synthetic body')
            ->and($canonical->rawBodyHtml)->toBeNull()
            ->and($canonical->attachments)->toHaveCount(5)
            ->and($canonical->attachments[0]->fileName)->toBeNull()
            ->and($canonical->attachments[0]->mediaType)->toBe('text/plain')
            ->and($canonical->attachments[0]->sizeBytes)->toBe(25)
            ->and(property_exists($canonical->attachments[0], 'providerAttachmentId'))->toBeFalse()
            ->and($canonical->attachments[1]->fileName)->toBe('synthetic.txt')
            ->and($canonical->attachments[2]->fileName)->toBe('synthetic.pdf')
            ->and($canonical->attachments[2]->sizeBytes)->toBe(6)
            ->and($mimeParts[0]->bodyData())->toBe(rtrim(strtr(base64_encode('Synthetic body'), '+/', '-_'), '='))
            ->and($mimeParts[1]->isAttachment())->toBeTrue()
            ->and($mimeParts[1]->attachmentId)->toBeNull()
            ->and($mimeParts[1]->headers['content-disposition'])->toBe('attachment')
            ->and($mimeParts[1]->bodyData())->toBeNull()
            ->and($mimeParts[2]->isAttachment())->toBeTrue()
            ->and($mimeParts[2]->filename)->toBe('synthetic.txt')
            ->and($mimeParts[2]->bodyData())->toBeNull()
            ->and($mimeParts[3]->isAttachment())->toBeTrue()
            ->and($mimeParts[3]->bodyData())->toBeNull()
            ->and($mimeParts[4]->isAttachment())->toBeTrue()
            ->and($mimeParts[4]->headers['content-disposition'])->toStartWith('attachment')
            ->and($mimeParts[4]->bodyData())->toBeNull()
            ->and($mimeParts[5]->isAttachment())->toBeTrue()
            ->and($mimeParts[5]->bodyData())->toBeNull()
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('U0VDUkVU')
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('SECRET')
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('SU1BR0U=')
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('Synthetic text attachment')
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('Synthetic attached HTML')
            ->and(json_encode($threadMessages, JSON_THROW_ON_ERROR))->not->toContain('IMAGE')
            ->and(method_exists($source, 'send'))->toBeFalse()
            ->and(method_exists($source, 'modifyMessage'))->toBeFalse();

        expect($history)->toHaveCount(2);
        foreach ($history as $request) {
            expect($request['request']->getMethod())->toBe('GET');
        }
        expect((string) $history[1]['request']->getUri())->toContain('format=full');
        expect((string) $history[1]['request']->getUri())->not->toContain('/attachments/');
    });

    it('maps_an_expired_history_id_to_a_full_resync_signal', function () {
        $handler = HandlerStack::create(new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 404, 'message' => 'synthetic expired history'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $client = new GoogleClient;
        $client->setHttpClient(new HttpClient(['handler' => $handler]));
        $source = new GoogleGmailMailboxSource(
            new AccessToken(new OAuthSecret('synthetic-access-token'), new DateTimeImmutable('+1 hour')),
            $client,
        );

        expect(fn () => $source->listHistory('12345', null))->toThrow(HistoryExpired::class);
    });
});
