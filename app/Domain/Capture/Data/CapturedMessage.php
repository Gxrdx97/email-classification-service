<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

final readonly class CapturedMessage
{
    public function __construct(
        public CanonicalMessage $canonical,
        public EnrichedMessage $enrichment,
    ) {
        if (
            $canonical->providerMessageId !== $enrichment->providerMessageId
            || $canonical->contentHash !== $enrichment->contentHash
        ) {
            throw new \InvalidArgumentException('A captura e o enriquecimento devem referenciar a mesma Versão da mensagem.');
        }
    }
}
