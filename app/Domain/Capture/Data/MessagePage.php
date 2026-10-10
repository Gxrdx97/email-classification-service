<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

final readonly class MessagePage
{
    /** @param list<MessageReference> $messages */
    public function __construct(
        public array $messages,
        public ?string $nextPageToken,
    ) {}
}
