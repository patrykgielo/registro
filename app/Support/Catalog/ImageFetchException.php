<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use RuntimeException;

/** A remote image was refused (policy) or could not be fetched/validated. Message is operator-facing. */
final class ImageFetchException extends RuntimeException {}
