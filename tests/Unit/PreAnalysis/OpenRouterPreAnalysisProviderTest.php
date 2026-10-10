<?php

declare(strict_types=1);

use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisSubmission;
use App\Domain\PreAnalysis\Exceptions\InvalidPreAnalysisOutput;
use App\Domain\PreAnalysis\Exceptions\PreAnalysisProviderException;
use App\Domain\PreAnalysis\Services\PreAnalysisOutputValidator;
use App\Infrastructure\OpenRouter\OpenRouterHttpResponse;
use App\Infrastructure\OpenRouter\OpenRouterPreAnalysisProvider;
use App\Infrastructure\OpenRouter\OpenRouterSettings;
use App\Infrastructure\OpenRouter\OpenRouterTransport;

afterEach(function (): void {
    Mockery::close();
});

describe('adaptador consultivo do OpenRouter', function () {
    it('fica desabilitado por padrão e não envia requisição', function () {
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldNotReceive('post');
        $provider = new OpenRouterPreAnalysisProvider(
            OpenRouterSettings::fromArray([]),
            $transport,
            new PreAnalysisOutputValidator,
        );

        expect($provider->isEnabled())->toBeFalse()
            ->and(fn () => $provider->analyze(makeOpenRouterSubmission()))
            ->toThrow(PreAnalysisProviderException::class);
    });

    it('exige modelo fixo e credencial quando habilitado', function () {
        expect(fn () => OpenRouterSettings::fromArray([
            'enabled' => true,
            'api_key' => 'synthetic-key',
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('recusa o seletor automático do OpenRouter mesmo quando o provider foi habilitado', function () {
        expect(fn () => OpenRouterSettings::fromArray([
            'enabled' => true,
            'api_key' => 'synthetic-key',
            'model' => 'openrouter/auto',
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('envia modelo fixo, limite e schema fechado e valida a proposta retornada', function () {
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldReceive('post')
            ->once()
            ->with(
                'https://openrouter.ai/api/v1/chat/completions',
                'synthetic-key',
                Mockery::on(function (array $body): bool {
                    $schema = $body['response_format']['json_schema'];

                    return $body['model'] === 'provider/model-v1'
                        && $body['stream'] === false
                        && $body['max_completion_tokens'] === 512
                        && $schema['strict'] === true
                        && $schema['schema']['additionalProperties'] === false
                        && $schema['schema']['properties']['suggestions']['items']['additionalProperties'] === false
                        && ! array_key_exists('tools', $body);
                }),
                30,
            )
            ->andReturn(openRouterSuccessfulResponse());

        $provider = new OpenRouterPreAnalysisProvider(
            openRouterEnabledSettings(),
            $transport,
            new PreAnalysisOutputValidator,
        );
        $proposal = $provider->analyze(makeOpenRouterSubmission());

        expect($proposal->suggestions)->toHaveCount(1)
            ->and($proposal->suggestions[0]->categoryVersionId)->toBe(101)
            ->and($proposal->suggestions[0]->probability)->toBe(0.75)
            ->and($proposal->usage)->toBe([
                'prompt_tokens' => 15,
                'completion_tokens' => 9,
                'total_tokens' => 24,
            ]);
    });

    it('rejeita saída malformada sem reutilizar texto bruto do provedor', function () {
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldReceive('post')->once()->andReturn(openRouterSuccessfulResponse('{"suggestions":[]}'));
        $provider = new OpenRouterPreAnalysisProvider(
            openRouterEnabledSettings(),
            $transport,
            new PreAnalysisOutputValidator,
        );

        expect(fn () => $provider->analyze(makeOpenRouterSubmission()))
            ->toThrow(InvalidPreAnalysisOutput::class, 'invalid structured proposal');
    });

    it('rejeita versão de Categoria desconhecida mesmo que a saída seja JSON válida', function () {
        $unknownCategory = json_encode([
            'suggestions' => [[
                'category_version_id' => 999,
                'probability' => 0.99,
                'uncertainty_code' => null,
                'structural_conflict' => false,
            ]],
            'none_applies' => false,
            'inconclusive_reason' => null,
        ], JSON_THROW_ON_ERROR);
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldReceive('post')->once()->andReturn(openRouterSuccessfulResponse($unknownCategory));
        $provider = new OpenRouterPreAnalysisProvider(
            openRouterEnabledSettings(),
            $transport,
            new PreAnalysisOutputValidator,
        );

        expect(fn () => $provider->analyze(makeOpenRouterSubmission()))
            ->toThrow(InvalidPreAnalysisOutput::class);
    });

    it('descarta respostas para um modelo diferente do solicitado', function () {
        $response = openRouterSuccessfulResponse();
        $body = $response->body;
        $body['model'] = 'other/model-v2';
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldReceive('post')->once()->andReturn(new OpenRouterHttpResponse(200, $body));
        $provider = new OpenRouterPreAnalysisProvider(
            openRouterEnabledSettings(),
            $transport,
            new PreAnalysisOutputValidator,
        );

        expect(fn () => $provider->analyze(makeOpenRouterSubmission()))
            ->toThrow(PreAnalysisProviderException::class, 'provider request failed');
    });

    it('rejeita payload acima do limite antes da fronteira HTTP', function () {
        $transport = Mockery::mock(OpenRouterTransport::class);
        $transport->shouldNotReceive('post');
        $provider = new OpenRouterPreAnalysisProvider(
            openRouterEnabledSettings(),
            $transport,
            new PreAnalysisOutputValidator,
        );
        $submission = new PreAnalysisSubmission(
            makeOpenRouterSubmission()->identity,
            'question-v1',
            'schema-v1',
            [101],
            ['thread' => ['body' => str_repeat('x', 66_000)]],
        );

        expect(fn () => $provider->analyze($submission))
            ->toThrow(PreAnalysisProviderException::class, 'provider request failed');
    });
});

function makeOpenRouterSubmission(): PreAnalysisSubmission
{
    return new PreAnalysisSubmission(
        new PreAnalysisIdentity(1, 2, str_repeat('b', 64), 'catalog-v1', 'provider/model-v1', 'privacy-v1'),
        'question-v1',
        'schema-v1',
        [101],
        [
            'question' => 'Suggest a category.',
            'thread' => [['body' => '[REDACTED]']],
            'categories' => [['category_version_id' => 101, 'name' => 'Billing']],
            'examples' => [],
        ],
    );
}

function openRouterEnabledSettings(): OpenRouterSettings
{
    return OpenRouterSettings::fromArray([
        'enabled' => true,
        'base_url' => 'https://openrouter.ai/api/v1',
        'api_key' => 'synthetic-key',
        'model' => 'provider/model-v1',
        'timeout' => 30,
    ]);
}

function openRouterSuccessfulResponse(?string $content = null): OpenRouterHttpResponse
{
    $content ??= json_encode([
        'suggestions' => [[
            'category_version_id' => 101,
            'probability' => 0.75,
            'uncertainty_code' => null,
            'structural_conflict' => false,
        ]],
        'none_applies' => false,
        'inconclusive_reason' => null,
    ], JSON_THROW_ON_ERROR);

    return new OpenRouterHttpResponse(200, [
        'model' => 'provider/model-v1',
        'choices' => [[
            'finish_reason' => 'stop',
            'message' => ['content' => $content],
        ]],
        'usage' => [
            'prompt_tokens' => 15,
            'completion_tokens' => 9,
            'total_tokens' => 24,
            'provider_payload' => 'must-not-persist',
        ],
    ]);
}
