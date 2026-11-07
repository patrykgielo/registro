<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use RuntimeException;

/**
 * The GLOBAL budget of the image phase (time or bytes) is spent. Deliberately NOT an
 * ImageFetchException: that one is a per-image failure the prefetch memoizes and carries on from;
 * this one ends the whole phase.
 */
final class ImageBudgetExceededException extends RuntimeException {}
