<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CaptureCommit;

interface CaptureStore
{
    /**
     * Persiste cada página atomicamente. Grava mensagens, Versões, enriquecimentos, Labels
     * e snapshots das Threads alteradas na mesma transação. Uma página intermediária tem
     * cursorAfter=null e nunca altera o Cursor nem cria intenções de Pré-análise; somente o
     * batch final avança o Cursor e pode criar intenções para touchedThreadIds elegíveis.
     *
     * O adaptador deve deduplicar por account_id + provider message ID, inserir uma Versão
     * somente para um content_hash ainda não visto, atualizar Labels sem versionar conteúdo,
     * fazer upsert idempotente dos fatos de enriquecimento por message_version/extractor e,
     * ao inserir uma Message em Thread com decisão oficial, definir pending sem limpar
     * latest_official_classification_id nem os Labels herdados. O snapshot usa as últimas
     * Versões por Message e seus enriquecimentos, sem Labels, anexos ou IDs brutos do Gmail.
     * expectedCursor é compare-and-swap; conflito deve reverter a transação. Repetir um lote
     * após falha é seguro.
     */
    public function commitBatch(CaptureBatch $batch): CaptureCommit;
}
