<?php

declare(strict_types=1);

use App\Domain\PreAnalysis\Contracts\PreAnalysisContextSource;
use App\Domain\PreAnalysis\Contracts\PreAnalysisExecutionRepository;
use App\Domain\PreAnalysis\Contracts\PreAnalysisOperationalFailureRecorder;
use App\Domain\PreAnalysis\Contracts\PreAnalysisProvider;
use App\Domain\PreAnalysis\Data\PreAnalysisCategoryVersion;
use App\Domain\PreAnalysis\Data\PreAnalysisContext;
use App\Domain\PreAnalysis\Data\PreAnalysisDispatchPolicy;
use App\Domain\PreAnalysis\Data\PreAnalysisExample;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use App\Domain\PreAnalysis\Data\PreAnalysisSuggestion;
use App\Domain\PreAnalysis\Enums\PreAnalysisExecutionOutcome;
use App\Domain\PreAnalysis\Exceptions\PreAnalysisProviderException;
use App\Domain\PreAnalysis\Exceptions\RetryablePreAnalysisException;
use App\Domain\PreAnalysis\Services\PreAnalysisOutboxConsumer;
use App\Domain\PreAnalysis\Services\PreAnalysisOutputValidator;
use App\Domain\PreAnalysis\Services\PreAnalysisProcessor;
use App\Domain\Privacy\Contracts\ThreadPrivacyConsentRepository;
use App\Domain\Privacy\Enums\PrivacyAction;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use App\Domain\Privacy\Services\DlpScanner;
use App\Domain\Privacy\Services\PayloadRedactor;
use App\Domain\Privacy\Services\PrivacyGate;
use App\Domain\Privacy\Services\PromptInjectionDetector;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

afterEach(function (): void {
    Mockery::close();
});

describe('execução de Pré-análise', function () {
    it('envia payload sinalizado por flag somente após consentimento allow da mesma Thread', function () {
        $identity = processorIdentity();
        $lease = processorLease($identity, 1);
        $context = processorContext($identity, PrivacyAction::Flag, [['body' => 'reader@example.invalid']]);
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldReceive('analyze')->once()->with(Mockery::on(
            fn ($submission): bool => $submission->identity->threadId === $identity->threadId
                && $submission->payload['thread']['messages'][0]['body'] === 'reader@example.invalid',
        ))->andReturn(validProcessorProposal());
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('complete')->once()->andReturn(true);
        $consents = Mockery::mock(ThreadPrivacyConsentRepository::class);
        $consents->shouldReceive('decisionFor')
            ->once()
            ->with($identity->threadId, $identity->privacyPolicyVersion)
            ->andReturn(PrivacyConsentDecision::Allow);

        $processor = processorService($provider, $repository, $consents);

        expect($processor->process($lease, $context, 5))->toBe(PreAnalysisExecutionOutcome::Completed);
    });

    it('mantém campos permitidos, Categorias ativas, Exemplos elegíveis e proveniência no payload externo', function () {
        $identity = processorIdentity();
        $context = new PreAnalysisContext(
            $identity,
            true,
            false,
            PrivacyAction::Abort,
            'question-v2',
            'schema-v2',
            'Choose only an active Category version.',
            [
                'messages' => [[
                    'from' => 'sender@example.invalid',
                    'subject' => 'Synthetic subject',
                    'body' => 'Synthetic permitted text',
                ]],
                'attachments' => ['attachment_bytes' => 'SYNTHETIC_ATTACHMENT_BYTES'],
            ],
            [new PreAnalysisCategoryVersion(101, 9, 2, 'Support', 'support')],
            [
                new PreAnalysisExample(501, 4, 101, 'Eligible example'),
                new PreAnalysisExample(502, 1, 999, 'Ineligible example'),
            ],
        );

        $payload = $context->providerPayload();

        expect($payload['thread'])->toBe([
            'messages' => [[
                'from' => 'sender@example.invalid',
                'subject' => 'Synthetic subject',
                'body' => 'Synthetic permitted text',
            ]],
        ])
            ->and($payload['categories'])->toBe([[
                'category_version_id' => 101,
                'name' => 'Support',
                'slug' => 'support',
                'description' => null,
                'instructions' => null,
            ]])
            ->and($payload['examples'])->toBe([[
                'example_id' => 501,
                'version' => 4,
                'category_version_id' => 101,
                'text' => 'Eligible example',
            ]])
            ->and($context->allowedCategoryVersionIds())->toBe([101])
            ->and($context->exampleProvenance())->toBe([[
                'id' => 501,
                'version' => 4,
                'category_version_id' => 101,
            ]])
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('SYNTHETIC_ATTACHMENT_BYTES');
    });

    /** @param array<string, mixed> $injectedFields */
    it('bloqueia de forma sanitizada antes do provedor diante de campos ou estruturas desconhecidos na Thread', function (
        array $injectedFields,
    ): void {
        $identity = processorIdentity();
        $lease = processorLease($identity, 1);
        $context = processorContext($identity, PrivacyAction::Abort, [array_merge(
            ['body' => 'Synthetic permitted text'],
            $injectedFields,
        )]);
        expect($context->providerPayload())->toBe([]);

        /** @var PreAnalysisProvider&MockInterface $provider */
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldNotReceive('analyze');
        /** @var PreAnalysisExecutionRepository&MockInterface $repository */
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('block')->once()->with(
            $lease,
            'privacy_payload_unavailable',
            Mockery::type(DateTimeImmutable::class),
        )->andReturn(true);
        $repository->shouldNotReceive('complete');
        /** @var ThreadPrivacyConsentRepository&MockInterface $consents */
        $consents = Mockery::mock(ThreadPrivacyConsentRepository::class);
        $consents->shouldNotReceive('decisionFor');
        $processor = processorService($provider, $repository, $consents);

        expect($processor->process($lease, $context, 5))->toBe(PreAnalysisExecutionOutcome::Blocked);
    })->with([
        'body_data container' => [['body_data' => ['bytes' => 'SYNTHETIC_BODY_DATA']]],
        'content field' => [['content' => 'SYNTHETIC_CONTENT']],
        'generic base64 field' => [['data' => 'U1lOVEhFVElDX0JBU0U2NA==']],
        'nested body structure' => [['body' => ['data' => 'SYNTHETIC_NESTED_BYTES']]],
    ]);

    it('agenda uma repetição tipada e sanitizada para falha temporária antes do limite', function () {
        $identity = processorIdentity();
        $lease = processorLease($identity, 1);
        $context = processorContext($identity, PrivacyAction::Abort, [['body' => 'synthetic clean text']]);
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldReceive('analyze')->once()->andThrow(new PreAnalysisProviderException('rate_limited', true));
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('releaseForRetry')->once()->with($lease)->andReturn(true);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with('preanalysis.provider_retry', Mockery::on(
            fn (array $context): bool => $context['error_code'] === 'rate_limited'
                && ! array_key_exists('exception', $context),
        ));

        $processor = processorService($provider, $repository, Mockery::mock(ThreadPrivacyConsentRepository::class), $logger);

        expect(fn () => $processor->process($lease, $context, 5))
            ->toThrow(RetryablePreAnalysisException::class, 'temporary pre-analysis failure');
    });

    it('registra falha final tipada sem substituir resultado oficial e mantém a Revisão disponível', function () {
        $identity = processorIdentity();
        $lease = processorLease($identity, 5);
        $context = processorContext($identity, PrivacyAction::Abort, [['body' => 'synthetic clean text']]);
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldReceive('analyze')->once()->andThrow(new PreAnalysisProviderException('upstream_unavailable', true));
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('fail')->once()->with(
            $lease,
            'upstream_unavailable',
            Mockery::type(DateTimeImmutable::class),
        )->andReturn(true);
        $failureRecorder = Mockery::mock(PreAnalysisOperationalFailureRecorder::class);
        $failureRecorder->shouldReceive('markFailedWithoutReplacingOfficialResult')
            ->once()
            ->with($identity->threadId, 'upstream_unavailable');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('preanalysis.provider_failed', Mockery::on(
            fn (array $context): bool => $context['error_code'] === 'upstream_unavailable'
                && ! array_key_exists('provider_response', $context),
        ));

        $processor = processorService(
            $provider,
            $repository,
            Mockery::mock(ThreadPrivacyConsentRepository::class),
            $logger,
            $failureRecorder,
        );

        expect($processor->process($lease, $context, 5))->toBe(PreAnalysisExecutionOutcome::Failed);
    });

    it('não registra mensagem de exceção bruta do provedor', function () {
        $identity = processorIdentity();
        $lease = processorLease($identity, 5);
        $context = processorContext($identity, PrivacyAction::Abort, [['body' => 'clean synthetic message']]);
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldReceive('analyze')->once()->andThrow(new RuntimeException(
            'private.person@example.invalid Bearer sensitive-token raw provider body',
        ));
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('fail')->once()->with(
            $lease,
            'provider_failure',
            Mockery::type(DateTimeImmutable::class),
        )->andReturn(true);
        $failureRecorder = Mockery::mock(PreAnalysisOperationalFailureRecorder::class);
        $failureRecorder->shouldReceive('markFailedWithoutReplacingOfficialResult')
            ->once()
            ->with($identity->threadId, 'provider_failure');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(
            'preanalysis.provider_failed',
            Mockery::on(function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['error_code'] === 'provider_failure'
                    && ! str_contains($encoded, 'private.person@example.invalid')
                    && ! str_contains($encoded, 'sensitive-token')
                    && ! str_contains($encoded, 'raw provider body');
            }),
        );

        $processor = processorService(
            $provider,
            $repository,
            Mockery::mock(ThreadPrivacyConsentRepository::class),
            $logger,
            $failureRecorder,
        );

        expect($processor->process($lease, $context, 5))->toBe(PreAnalysisExecutionOutcome::Failed);
    });

    it('revalida sugestões de qualquer adapter contra a allowlist ativa', function () {
        $identity = processorIdentity();
        $lease = processorLease($identity, 2);
        $context = processorContext($identity, PrivacyAction::Abort, [['body' => 'clean']]);
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldReceive('isEnabled')->once()->andReturn(true);
        $provider->shouldReceive('analyze')->once()->andReturn(new PreAnalysisProposal(
            [new PreAnalysisSuggestion(999, 0.99, null, false)],
            false,
            null,
        ));
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('fail')->once()->with(
            $lease,
            'invalid_provider_output',
            Mockery::type(DateTimeImmutable::class),
        )->andReturn(true);
        $failureRecorder = Mockery::mock(PreAnalysisOperationalFailureRecorder::class);
        $failureRecorder->shouldReceive('markFailedWithoutReplacingOfficialResult')
            ->once()
            ->with($identity->threadId, 'invalid_provider_output');

        $processor = processorService(
            $provider,
            $repository,
            Mockery::mock(ThreadPrivacyConsentRepository::class),
            null,
            $failureRecorder,
        );

        expect($processor->process($lease, $context, 5))->toBe(PreAnalysisExecutionOutcome::Failed);
    });

    it('deduplica uma segunda entrega usando o claim atômico e a chave estável da outbox', function () {
        $identity = processorIdentity();
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('claim')
            ->twice()
            ->with(42, Mockery::type(DateTimeImmutable::class), 60)
            ->andReturn(null);
        $source = Mockery::mock(PreAnalysisContextSource::class);
        $source->shouldNotReceive('load');
        $processor = processorService(
            Mockery::mock(PreAnalysisProvider::class),
            $repository,
            Mockery::mock(ThreadPrivacyConsentRepository::class),
        );
        $consumer = new PreAnalysisOutboxConsumer(
            $repository,
            $source,
            $processor,
            new PreAnalysisDispatchPolicy('jev', true, $identity->providerModel),
        );

        expect($consumer->consume(42, 'pre-analysis:42'))->toBe(PreAnalysisExecutionOutcome::Duplicate)
            ->and($consumer->consume(42, 'pre-analysis:42'))->toBe(PreAnalysisExecutionOutcome::Duplicate);
    });

    it('bloqueia antes do provedor se o Cursor não foi confirmado ou já existe resultado oficial', function (
        bool $cursorCommitted,
        bool $hasOfficialResult,
    ): void {
        $identity = processorIdentity();
        $lease = processorLease($identity, 1);
        $context = processorContext($identity, PrivacyAction::Abort, [['body' => 'clean']]);
        $context = new PreAnalysisContext(
            $context->identity,
            $cursorCommitted,
            $hasOfficialResult,
            $context->privacyAction,
            $context->questionVersion,
            $context->schemaVersion,
            $context->question,
            $context->thread,
            $context->categoryVersions,
            $context->examples,
        );
        $provider = Mockery::mock(PreAnalysisProvider::class);
        $provider->shouldNotReceive('analyze');
        $provider->shouldNotReceive('isEnabled');
        $repository = Mockery::mock(PreAnalysisExecutionRepository::class);
        $repository->shouldReceive('claim')->once()->andReturn($lease);
        $repository->shouldReceive('block')->once()->with(
            $lease,
            'not_eligible',
            Mockery::type(DateTimeImmutable::class),
        )->andReturn(true);
        $source = Mockery::mock(PreAnalysisContextSource::class);
        $source->shouldReceive('load')->once()->with($lease)->andReturn($context);
        $processor = processorService($provider, $repository, Mockery::mock(ThreadPrivacyConsentRepository::class));
        $consumer = new PreAnalysisOutboxConsumer(
            $repository,
            $source,
            $processor,
            new PreAnalysisDispatchPolicy('jev', true, $identity->providerModel),
        );

        expect($consumer->consume(42, 'pre-analysis:42'))->toBe(PreAnalysisExecutionOutcome::Blocked);
    })->with([
        'Cursor not committed' => [false, false],
        'Official result already exists' => [true, true],
    ]);
});

function processorIdentity(): PreAnalysisIdentity
{
    return new PreAnalysisIdentity(1, 27, str_repeat('c', 64), 'catalog-v2', 'provider/model-v2', 'privacy-v4');
}

function processorLease(PreAnalysisIdentity $identity, int $attempt): PreAnalysisLease
{
    return new PreAnalysisLease(42, $identity, new DateTimeImmutable('2026-10-05T10:00:00+00:00'), $attempt);
}

/** @param list<array<string, mixed>> $messages */
function processorContext(PreAnalysisIdentity $identity, PrivacyAction $action, array $messages): PreAnalysisContext
{
    return new PreAnalysisContext(
        $identity,
        true,
        false,
        $action,
        'question-v2',
        'schema-v2',
        'Choose only an active Category version.',
        ['messages' => $messages],
        [new PreAnalysisCategoryVersion(101, 9, 2, 'Support', 'support')],
        [],
    );
}

function validProcessorProposal(): PreAnalysisProposal
{
    return new PreAnalysisProposal([new PreAnalysisSuggestion(101, 0.8, null, false)], false, null);
}

function processorService(
    PreAnalysisProvider $provider,
    PreAnalysisExecutionRepository $repository,
    ThreadPrivacyConsentRepository $consents,
    ?LoggerInterface $logger = null,
    ?PreAnalysisOperationalFailureRecorder $failureRecorder = null,
): PreAnalysisProcessor {
    $logger ??= Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $failureRecorder ??= Mockery::mock(PreAnalysisOperationalFailureRecorder::class)->shouldIgnoreMissing();

    return new PreAnalysisProcessor(
        $provider,
        $repository,
        $failureRecorder,
        $consents,
        new PrivacyGate(new DlpScanner, new PromptInjectionDetector, new PayloadRedactor, $logger),
        new PreAnalysisOutputValidator,
        $logger,
    );
}
