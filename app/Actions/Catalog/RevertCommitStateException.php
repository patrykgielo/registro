<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use RuntimeException;

/**
 * An exception reached the revert while/after its transaction was committing and a fresh read showed
 * the batch DID change ($committed) — or could not rule it out. The caller must NOT claim a rollback
 * ("nic nie usunięto"): the operator is sent to catalog:import-status and --cleanup-only.
 */
final class RevertCommitStateException extends RuntimeException
{
    public function __construct(public readonly int $batchId, public readonly bool $committed, \Throwable $previous)
    {
        parent::__construct(
            ($committed ? "Transakcja cofnięcia paczki #{$batchId} została ZATWIERDZONA" : "Stan cofnięcia paczki #{$batchId} nieznany — nie udało się ustalić, czy zmiany zostały zatwierdzone").'. Błąd: '.$previous->getMessage(),
            0,
            $previous
        );
    }
}
