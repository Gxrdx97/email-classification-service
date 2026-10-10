<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenRouter;

final readonly class OpenRouterHttpResponse
{
    public function __construct(
        public int $status,
        public mixed $body,
    ) {}
}
