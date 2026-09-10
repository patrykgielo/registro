<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToOrganization;
use App\Traits\NormalizesEmptyJsonToNull;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Location extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, NormalizesEmptyJsonToNull;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'code',
        'street',
        'building',
        'postal_code',
        'city',
        'latitude',
        'longitude',
        'phone',
        'email',
        'opening_hours',
        'photo',
        'gallery',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'opening_hours' => 'array',
        'gallery' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'primary_slot' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            if (empty($location->slug) && ! empty($location->name)) {
                $location->slug = Str::slug($location->name);
            }
        });
    }

    protected function normalizeEmptyJsonToNullFields(): array
    {
        return ['opening_hours', 'gallery'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Atomically switches which location is primary for its organization.
     *
     * Unlike a plain `$location->primary_slot = 1; $location->save();`
     * (which LocationObserver::updating() still defends against a UNIQUE
     * violation, but only via two sequential commits — see its docblock),
     * this wraps the demotion of the old primary AND the promotion of the
     * new one in a single transaction. This is the method the future
     * "ustaw jako główny" one-click action (plan-wdrozenia.md step 1.3)
     * should call — not assign `primary_slot` directly.
     */
    public static function promoteToPrimary(self $location): void
    {
        DB::transaction(function () use ($location) {
            static::withoutGlobalScope('organization')
                ->where('organization_id', $location->organization_id)
                ->where('primary_slot', 1)
                ->whereKeyNot($location->getKey())
                ->lockForUpdate()
                ->update(['primary_slot' => null]);

            $location->primary_slot = 1;
            $location->save();
        });
    }

    /**
     * Single source of truth for the "every tenant keeps at least one
     * location" half of the delete guard — used by both
     * LocationResource::guardDeletion() (Filament UI notification) and
     * LocationObserver::deleting() (the model-layer backstop). Scoped
     * explicitly by `organization_id` rather than relying on the ambient
     * tenant global scope, so it gives the right answer from any caller
     * (Filament, tinker, a console command), not just an HTTP request with a
     * resolved tenant.
     */
    public function isOnlyLocationForOrganization(): bool
    {
        return static::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization_id)
            ->count() <= 1;
    }

    /**
     * The other half of the delete guard — see isOnlyLocationForOrganization().
     * Deliberately reads the in-memory attribute rather than querying: unlike
     * the sibling count, "is this specific row primary" never needs a fresh
     * DB read to answer correctly for the row being deleted.
     */
    public function isPrimary(): bool
    {
        return (int) $this->primary_slot === 1;
    }

    /**
     * Faza 6 code review (2026-09-10) — the deactivation-side twin of
     * isOnlyLocationForOrganization(). `LocationObserver::deleting()` has
     * always guarded HARD-deleting the last location; nothing guarded
     * DEACTIVATING it, which strands every customer with an existing cart
     * at checkout with no way to fix it (LocationContext::selectionRequired()
     * is false at zero active locations, so no switcher renders either —
     * see LocationObserver's own docblock and CheckoutPickupLocationTest for
     * the reproduced dead end).
     *
     * Excludes THIS row's own id — asking "if I turn this one off, would
     * zero remain", not "am I currently the only one". Used from
     * `LocationObserver::updating()` only — a create-side use was tried and
     * reverted (see that method's own note: a not-yet-existing row cannot
     * strand an existing customer cart, so guarding it there only broke the
     * legitimate "create a branch as a draft" workflow). Would work
     * identically from creating() too if ever needed again — a
     * not-yet-persisted row has no id yet, and whereKeyNot(null) matches
     * everything, same as no filter at all.
     */
    public function isOnlyActiveLocationForOrganization(): bool
    {
        return static::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    /**
     * Single-line formatted address, added for Faza 6 krok 6.3
     * (`CartService::convertToOrder()`) to build `orders.
     * pickup_location_address` at checkout time — a POINT-IN-TIME snapshot,
     * not a live reference, so callers must copy the return value rather
     * than re-deriving it later from a possibly-renamed/deleted Location.
     *
     * Correction (code review 2026-09-10): this method does NOT consolidate
     * any existing duplicate. The shape mirrors two pre-existing, UNTOUCHED
     * copies of the same "street+building, postal_code+city" format
     * (`resources/views/components/ios/location-card.blade.php` for this
     * same Location entity, and `AppointmentResource.php:275` for a
     * different address entirely) — both still have their own inline
     * logic. A real consolidation (making those two call this method
     * instead) was NOT done here and would be a separate, deliberate
     * change, not a side effect of adding this one.
     */
    public function formattedAddress(): string
    {
        $street = trim(($this->street ?? '').' '.($this->building ?? ''));
        $locality = trim(($this->postal_code ?? '').' '.($this->city ?? ''));

        return trim(implode(', ', array_filter([$street, $locality], fn (string $part): bool => $part !== '')));
    }
}
