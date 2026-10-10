<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenRouter;

interface OpenRouterTransport
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $url, string $apiKey, array $body, int $timeoutSeconds): OpenRouterHttpResponse;
}
