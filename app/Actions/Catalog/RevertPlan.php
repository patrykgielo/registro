<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

/** Read-only outcome of RevertCatalogImport::analyse() — what a revert WOULD do, and what stops it. */
final class RevertPlan
{
    /** Ledger/identity problems no flag can override (id reuse, wrong organization, wrong batch state). @var array<int, string> */
    public array $fatal = [];

    /** @var array<int, int> service ids that will be deleted */
    public array $serviceIds = [];

    /** @var array<int, int> ledger service ids whose row no longer exists (deleted by an admin) */
    public array $goneServiceIds = [];

    /**
     * serviceId => ['name' => string, 'hard' => string[], 'soft' => string[]]
     * hard = never overridable; soft = overridable by --include-modified / --discard-cart-items.
     *
     * @var array<int, array{name: string, hard: array<int, string>, soft: array<int, string>}>
     */
    public array $blocked = [];

    /** @var array<int, int> */
    public array $unitIds = [];

    /** @var array<int, int> */
    public array $stockIds = [];

    /** @var array<int, int> */
    public array $foreignUnitIds = [];

    /** @var array<int, int> */
    public array $foreignStockIds = [];

    public int $cartItems = 0;

    /** @var array<int, string> id => slug of created-by-batch categories eligible for deletion (decided again after the services are gone) */
    public array $categoryCandidates = [];

    /** @var array<int, string> categories created by the batch that are edited since — kept */
    public array $categoriesKept = [];

    /** @var array<int, int> ledger file item ids to attempt to delete after commit */
    public array $fileItemIds = [];

    public bool $partial = false;

    public function canExecute(): bool
    {
        if ($this->fatal !== []) {
            return false;
        }

        if ($this->blocked !== [] && ! $this->partial) {
            return false;
        }

        return $this->serviceIds !== [] || $this->goneServiceIds !== [];
    }
}
