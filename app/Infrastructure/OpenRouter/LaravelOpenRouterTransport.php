<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenRouter;

use App\Domain\PreAnalysis\Exceptions\PreAnalysisProviderException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class LaravelOpenRouterTransport implements OpenRouterTransport
{
    public function post(string $url, string $apiKey, array $body, int $timeoutSeconds): OpenRouterHttpResponse
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeoutSeconds)
                ->post($url, $body);
        } catch (Throwable) {
            throw new PreAnalysisProviderException(
                'transport_failure',
                true,
            );
        }

        return new OpenRouterHttpResponse($response->status(), $response->json());
    }
}
