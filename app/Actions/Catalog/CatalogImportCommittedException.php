<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use RuntimeException;

/**
 * An exception reached the import AFTER (or while) the write transaction committed: a fresh read
 * showed the batch row exists (or could not be checked). The stored files are therefore KEPT —
 * deleting them could orphan live products — and the operator is sent to catalog:import-status.
 */
final class CatalogImportCommittedException extends RuntimeException
{
    public function __construct(public readonly ?int $batchId, public readonly bool $certain, \Throwable $previous)
    {
        parent::__construct(
            ($certain ? "Transakcja została zatwierdzona — paczka #{$batchId} ISTNIEJE" : 'Nie udało się ustalić, czy transakcja została zatwierdzona')
            .', pliki obrazów zostawione. Błąd: '.$previous->getMessage(),
            0,
            $previous
        );
    }
}
