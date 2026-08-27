<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Every organization that doesn't have a location yet gets one, built
     * from the contact details it already has (SettingsManager::
     * contactDetailsFor()) — tryb-jednooddzialowy.md's guarantee that a
     * tenant with a single site gets a real address the day this ships,
     * without typing anything.
     *
     * `contact.address_line` is a single free-text field (no separate
     * street/building split exists anywhere upstream — see
     * SystemSettings.php's `contact.address_line` TextInput), so it maps to
     * `street`; `building` stays null since there is nothing to parse it
     * from.
     *
     * name: "Siedziba główna" rather than the organization's own name — a
     * tenant that later adds branches ("Warszawa", "Gdańsk") would end up
     * with the org's brand name duplicated as a branch name otherwise, and
     * "Siedziba główna" reads correctly as an address entity regardless of
     * how many branches follow.
     *
     * An organization with no contact details at all (fresh signup, nothing
     * filled in yet) still gets a location — with a blank address to fill in
     * later — rather than being left with none. Faza 2 needs somewhere to
     * anchor the default stock row, and "no location yet" would leave it
     * with nowhere to write.
     *
     * Idempotent: skips any organization that already has a location, so
     * re-running after a rollback (or after this migration ran once and a
     * tenant since added their own locations) never creates a second
     * "Siedziba główna".
     */
    public function up(): void
    {
        /** @var SettingsManager $settings */
        $settings = app(SettingsManager::class);

        // Organization has no tenant scope of its own to bypass (it isn't
        // itself a BelongsToOrganization model) — plain query, which also
        // means SoftDeletes' default scope excludes closed/deleted tenants,
        // correctly: a closed org shouldn't get a new location.
        $organizationIds = Organization::query()->pluck('id');

        $existingOrgIdsWithLocation = DB::table('locations')
            ->distinct()
            ->pluck('organization_id')
            ->all();

        foreach ($organizationIds as $organizationId) {
            if (in_array($organizationId, $existingOrgIdsWithLocation, true)) {
                continue;
            }

            $organization = Organization::find($organizationId);

            if (! $organization) {
                continue;
            }

            $contact = $settings->contactDetailsFor($organization);

            DB::table('locations')->insert([
                'organization_id' => $organizationId,
                'name' => 'Siedziba główna',
                'slug' => Str::slug('Siedziba główna'),
                'code' => null,
                'street' => $contact['address_line'] !== '' ? $contact['address_line'] : null,
                'building' => null,
                'postal_code' => $contact['postal_code'] !== '' ? $contact['postal_code'] : null,
                'city' => $contact['city'] !== '' ? $contact['city'] : null,
                'latitude' => null,
                'longitude' => null,
                'phone' => $contact['phone'] !== '' ? $contact['phone'] : null,
                'email' => $contact['email'] !== '' ? $contact['email'] : null,
                'opening_hours' => null,
                'photo' => null,
                'gallery' => null,
                'description' => null,
                'is_active' => true,
                'sort_order' => 0,
                'primary_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Deliberate no-op — NOT a placeholder, a decision. Earlier revisions of
     * this method tried to delete only rows this migration could plausibly
     * have created, matched on `name = 'Siedziba główna' AND slug =
     * 'siedziba-glowna' AND primary_slot = 1 AND created_at = updated_at`.
     * That heuristic was rejected: "Siedziba główna" is not an arbitrary
     * marker, it is *exactly* the name this migration itself suggests, so an
     * admin who manually creates their own first location and happens to
     * accept the obvious default name produces a row this WHERE clause
     * cannot distinguish from one this migration inserted. `created_at =
     * updated_at` narrows that window but does not close it — a location
     * created and never touched again (very plausible for a tenant that
     * never has a second branch) satisfies it too. No column combination
     * available on this table can tell "the migration wrote this row" apart
     * from "a human wrote a row identical to what the migration would have
     * written". Rolling back on a guess would delete a tenant's real data.
     *
     * `throw new \RuntimeException(...)` (this repo's usual pattern for an
     * irreversible data migration, migrations.md) was considered and
     * rejected too: `migrate:rollback` walks migrations newest-first, and
     * this one sits directly on top of `..._120000_create_locations_table`.
     * A throw here would abort the batch before that migration's own down()
     * — which does the real, unconditionally-safe rollback: `DROP TABLE
     * locations` — ever runs. Refusing to roll back this migration would
     * therefore also block rolling back the one underneath it, which is a
     * strictly worse outcome than doing nothing here.
     *
     * So: this method intentionally leaves the backfilled rows in place.
     * That is not an inconsistent state — they are correct, ordinary
     * location rows (address data, or blank fields waiting to be filled in),
     * indistinguishable from ones a tenant created by hand, and up() is
     * idempotent (skips any organization that already has a location), so
     * re-running it after this "rollback" creates nothing new. The actual,
     * always-safe way to undo this feature is rolling back BOTH migrations
     * together, which drops the whole `locations` table — see
     * CreateLocationsTableMigrationTest for that path. Pinned by
     * BackfillPrimaryLocationForOrganizationsMigrationTest: rollback of this
     * migration alone must preserve every row, up() afterward must stay
     * idempotent, and rolling back both migrations together must drop the
     * table.
     */
    public function down(): void
    {
        // Real statement (not just a comment — `migrations:check-rollback`
        // strips comments before checking for an empty body) that records
        // the deliberate no-op decision above at the moment anyone actually
        // runs this rollback, rather than leaving a silent, unexplained gap
        // in the migration log.
        Log::info(
            'backfill_primary_location_for_organizations: down() is a deliberate no-op — '.
            'backfilled locations are preserved on rollback, see migration file docblock.'
        );
    }
};
