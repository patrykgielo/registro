<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use Closure;

/**
 * One budget for the whole image phase of an import: wall-clock time (retry waits, redirects and
 * the per-host delay included) and total bytes. SafeImageFetcher consults it before every attempt
 * and before every sleep; the importer feeds it the bytes of every distinct image. The clock is
 * injected (milliseconds) so tests never sleep.
 */
final class FetchBudget
{
    private readonly float $deadlineMs;

    private int $bytes = 0;

    /**
     * @param  Closure(): float  $clock  current time in milliseconds
     */
    public function __construct(private readonly Closure $clock, private readonly int $maxSeconds, private readonly int $maxBytes)
    {
        $this->deadlineMs = ($this->clock)() + $maxSeconds * 1000;
    }

    public function remainingMs(): float
    {
        return $this->deadlineMs - ($this->clock)();
    }

    /** @throws ImageBudgetExceededException */
    public function assertTime(): void
    {
        if ($this->remainingMs() <= 0) {
            throw new ImageBudgetExceededException("budżet czasu fazy obrazów ({$this->maxSeconds} s) wyczerpany");
        }
    }

    /** @throws ImageBudgetExceededException */
    public function consumeBytes(int $bytes): void
    {
        $this->bytes += $bytes;

        if ($this->bytes > $this->maxBytes) {
            throw new ImageBudgetExceededException('budżet transferu fazy obrazów ('.intdiv($this->maxBytes, 1024 * 1024).' MB) wyczerpany');
        }
    }
}
