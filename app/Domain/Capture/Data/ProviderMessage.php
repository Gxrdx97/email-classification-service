<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;
use DateTimeImmutable;
use JsonSerializable;

final readonly class ProviderMessage implements JsonSerializable
{
    /**
     * @param  list<string>  $labelIds
     * @param  array<string, list<string>>  $headers  Cabeçalhos relevantes normalizados, sem logs.
     */
    public function __construct(
        public string $providerMessageId,
        public string $providerThreadId,
        public array $labelIds,
        public array $headers,
        public ?DateTimeImmutable $internalDate,
        public MimePart $payload,
    ) {
        if ($providerMessageId === '' || $providerThreadId === '') {
            throw new InvalidMailboxData('O Gmail retornou uma mensagem sem identidade canônica.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'provider_thread_id' => $this->providerThreadId,
            'labels_count' => count($this->labelIds),
            'headers' => '[omitted]',
            'payload' => '[omitted]',
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
