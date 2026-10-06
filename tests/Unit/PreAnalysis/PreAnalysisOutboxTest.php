<?php

declare(strict_types=1);

use App\Domain\PreAnalysis\Contracts\PreAnalysisOutboxRepository;
use App\Domain\PreAnalysis\Contracts\PreAnalysisQueuePublisher;
use App\Domain\PreAnalysis\Data\PreAnalysisDispatchPolicy;
use App\Domain\PreAnalysis\Data\PreAnalysisOutboxMessage;
use App\Domain\PreAnalysis\Services\PreAnalysisConsentWakeup;
use App\Domain\PreAnalysis\Services\PreAnalysisOutboxDispatcher;
use App\Domain\Privacy\Contracts\ThreadPrivacyConsentRepository;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use Psr\Log\LoggerInterface;

afterEach(function (): void {
    Mockery::close();
});

describe('dispatcher da outbox de Pré-análise', function () {
    it('não reclama nem publica quando provider=none ou desabilitado', function () {
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldNotReceive('claimAvailable');
        $queue = Mockery::mock(PreAnalysisQueuePublisher::class);
        $queue->shouldNotReceive('publish');

        expect((new PreAnalysisOutboxDispatcher($outbox, $queue))->dispatchAvailable())->toBe(0);
    });

    it('publica somente mensagens reclamadas e marca a publicação após o envio à fila', function () {
        $now = new DateTimeImmutable('2026-10-05T10:00:00+00:00');
        $message = new PreAnalysisOutboxMessage(7, 42, 'pre-analysis:42', 1, $now);
        $policy = new PreAnalysisDispatchPolicy('jev', true, 'provider/model-v1');
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldReceive('claimAvailable')->once()->with(25, 60, 5, $now)->andReturn([$message]);
        $outbox->shouldReceive('markDispatched')->once()->with($message, $now)->andReturn(true);
        $queue = Mockery::mock(PreAnalysisQueuePublisher::class);
        $queue->shouldReceive('publish')->once()->with($message)->ordered();

        $dispatcher = new PreAnalysisOutboxDispatcher($outbox, $queue, $policy);

        expect($dispatcher->dispatchAvailable($now))->toBe(1);
    });

    it('aplica backoff exponencial limitado e não registra texto de exceção da fila', function () {
        $now = new DateTimeImmutable('2026-10-05T10:00:00+00:00');
        $message = new PreAnalysisOutboxMessage(8, 43, 'pre-analysis:43', 2, $now);
        $policy = new PreAnalysisDispatchPolicy(
            provider: 'jev',
            providerEnabled: true,
            providerModel: 'provider/model-v1',
            maxDispatchAttempts: 5,
            baseBackoffSeconds: 5,
            maxBackoffSeconds: 30,
        );
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldReceive('claimAvailable')->once()->andReturn([$message]);
        $outbox->shouldReceive('markFailed')->once()->with(
            $message,
            'queue_publish_failed',
            Mockery::on(fn ($availableAt): bool => $availableAt == $now->modify('+10 seconds')),
            false,
            $now,
        )->andReturn(true);
        $queue = Mockery::mock(PreAnalysisQueuePublisher::class);
        $queue->shouldReceive('publish')->once()->andThrow(new RuntimeException(
            'private.person@example.invalid bearer secret-value must-not-be-logged',
        ));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with(
            'preanalysis_outbox.publish_failed',
            Mockery::on(function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['error_code'] === 'queue_publish_failed'
                    && ! str_contains($encoded, 'private.person@example.invalid')
                    && ! str_contains($encoded, 'secret-value')
                    && ! str_contains($encoded, 'bearer');
            }),
        );

        $dispatcher = new PreAnalysisOutboxDispatcher($outbox, $queue, $policy, $logger);

        expect($dispatcher->dispatchAvailable($now))->toBe(0)
            ->and($policy->backoffSeconds(2))->toBe(10)
            ->and($policy->backoffSeconds(1000))->toBe(30);
    });

    it('encerra a intenção quando a tentativa de publicação atinge o limite', function () {
        $now = new DateTimeImmutable('2026-10-05T10:00:00+00:00');
        $message = new PreAnalysisOutboxMessage(9, 44, 'pre-analysis:44', 5, $now);
        $policy = new PreAnalysisDispatchPolicy('jev', true, 'provider/model-v1');
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldReceive('claimAvailable')->once()->andReturn([$message]);
        $outbox->shouldReceive('markFailed')->once()->with(
            $message,
            'attempts_exhausted',
            Mockery::on(fn ($availableAt): bool => $availableAt == $now->modify('+80 seconds')),
            true,
            $now,
        )->andReturn(true);
        $queue = Mockery::mock(PreAnalysisQueuePublisher::class);
        $queue->shouldReceive('publish')->once()->andThrow(new RuntimeException('synthetic failure'));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        $dispatcher = new PreAnalysisOutboxDispatcher($outbox, $queue, $policy, $logger);

        expect($dispatcher->dispatchAvailable($now))->toBe(0);
    });

    it('retoma apenas intenções bloqueadas da política para a qual a Thread concedeu allow', function () {
        $consents = Mockery::mock(ThreadPrivacyConsentRepository::class);
        $consents->shouldReceive('decisionFor')
            ->once()
            ->with(27, 'privacy-v4')
            ->andReturn(PrivacyConsentDecision::Allow);
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldReceive('resumeAwaitingConsent')
            ->once()
            ->with(27, 'privacy-v4', Mockery::type(DateTimeImmutable::class))
            ->andReturn(1);

        expect((new PreAnalysisConsentWakeup($consents, $outbox))->resumeAllowedThread(27, 'privacy-v4'))->toBe(1);
    });

    it('não retoma uma intenção após consentimento deny', function () {
        $consents = Mockery::mock(ThreadPrivacyConsentRepository::class);
        $consents->shouldReceive('decisionFor')
            ->once()
            ->with(27, 'privacy-v4')
            ->andReturn(PrivacyConsentDecision::Deny);
        $outbox = Mockery::mock(PreAnalysisOutboxRepository::class);
        $outbox->shouldNotReceive('resumeAwaitingConsent');

        expect((new PreAnalysisConsentWakeup($consents, $outbox))->resumeAllowedThread(27, 'privacy-v4'))->toBe(0);
    });
});
