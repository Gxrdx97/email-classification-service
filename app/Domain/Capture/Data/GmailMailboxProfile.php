<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;
use JsonSerializable;

final readonly class GmailMailboxProfile implements JsonSerializable
{
    public function __construct(
        public string $emailAddress,
        public string $historyId,
    ) {
        if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false || ! preg_match('/^\d+$/', $historyId)) {
            throw new InvalidMailboxData('O perfil retornado pelo Gmail é inválido.');
        }
    }

    /** @return array{email_address: string, history_id: string} */
    public function jsonSerialize(): array
    {
        return ['email_address' => '[redacted]', 'history_id' => $this->historyId];
    }

    /** @return array{email_address: string, history_id: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
