<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use RuntimeException;

/** Another catalog:import / catalog:import-revert of the same organization holds the lock. */
final class CatalogOperationInProgressException extends RuntimeException {}
