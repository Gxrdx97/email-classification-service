<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenRouter;

use App\Domain\PreAnalysis\Services\PreAnalysisOutputValidator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final readonly class OpenRouterPreAnalysisProviderFactory
{
    public function __construct(private ConfigRepository $config) {}

    public function make(): OpenRouterPreAnalysisProvider
    {
        $settings = $this->config->get('services.openrouter', []);

        return new OpenRouterPreAnalysisProvider(
            OpenRouterSettings::fromArray(is_array($settings) ? $settings : []),
            new LaravelOpenRouterTransport,
            new PreAnalysisOutputValidator,
        );
    }
}
