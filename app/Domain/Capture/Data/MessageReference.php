<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;

final readonly class MessageReference
{
    public function __construct(
        public string $providerMessageId,
        public string $providerThreadId,
    ) {
        if ($this->providerMessageId === '' || $this->providerThreadId === '') {
            throw new InvalidMailboxData('O Gmail retornou uma referência de mensagem inválida.');
        }
    }
}
