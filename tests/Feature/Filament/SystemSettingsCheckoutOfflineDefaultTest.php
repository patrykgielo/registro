<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SystemSettings;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins the failure mode found by code-reviewer on feature/offline-settlement-default — with the
 * REAL mechanism, verified empirically while writing this test. The review's own theory
 * ("Filament falls back to the field's ->default(false)") was wrong; so was this test's own
 * first attempt at a fix (a naive `if ($state === null)` inside afterStateHydrated — also
 * wrong, see below). Both are corrected here.
 *
 * SettingsManager::isOfflineSettlementEnabled() defaults to `true` when a tenant has no
 * `checkout.settlement_offline_enabled` row. Two compounding, independent Filament v4 mechanics
 * defeat that default on SystemSettings' own Toggle field:
 *
 * 1. `->default()` is NEVER consulted here. Filament's Schema::fill($state) only ever applies a
 *    field's ->default() when the WHOLE form is filled with a literal `null` (the "Create page,
 *    no record" case) — see vendor/filament/schemas/src/Concerns/HasState.php's fill()/
 *    hydrateDefaultState(). SystemSettings::mount() always calls
 *    fill($settingsManager->all()) with a REAL (non-null) array whenever the tenant has ANY
 *    setting in ANY group — so a key absent from that array hydrates to raw `null` instead,
 *    never the field's `->default(true)`.
 * 2. Toggle's own built-in state cast — BooleanStateCast(isNullable: false), see
 *    Toggle::getDefaultStateCasts() — then unconditionally coerces that `null` to `false`
 *    DURING hydrateState(), before ANY hydration hook (incl. afterStateHydrated) runs. This is
 *    why a naive `afterStateHydrated(fn ($state) => $state === null ? ... )` does NOT work
 *    either: `$state` has already been coerced to `false` by the time the hook sees it, making
 *    "no row at all" and "tenant explicitly chose false" indistinguishable at that point.
 *
 * HasGroupedSettings::persistSettingsGroup() then writes that `false` as a real row;
 * `isOfflineSettlementEnabled()` reads the now-existing row and returns `false` — flipping
 * offline settlement off for that tenant the instant they save ANY field in this tab, exactly
 * the end-user symptom the code review described.
 *
 * The actual fix (SystemSettings.php, same Toggle) checks the RAW setting directly —
 * `app(SettingsManager::class)->get('checkout.settlement_offline_enabled')` with NO default,
 * which returns `null` only when there is truly no row (tenant nor global) — BEFORE the cast
 * has a chance to collapse that distinction, and only then forces `$component->state(true)`.
 *
 * The SAME mechanism (1 above) affects every other field in this group with its own
 * ->default(): `settlement_online_enabled` (confirmed empirically: a fresh tenant with REAL P24
 * credentials configured still gets isOnlineSettlementEnabled() === false after any save of
 * this tab) and `offline_reservation_hold_hours` (TextInput, no BooleanStateCast — lands as
 * `null` in the DB, so offlineReservationHoldHours() clamps it to 1h instead of the intended
 * 48h). `pesel_required` happens to be unaffected: its coerced-false-on-null accidentally
 * matches its own code default (also false). NONE of these three are fixed here — out of scope
 * for the offline-settlement default, reported separately.
 *
 * UPDATE 2026-08-22 (feature/checkout-settings-unsaveable): the real UI path
 * (`Livewire::test(...)->call('saveCheckoutSettings')`) used to be unreachable for ANY tenant,
 * in ANY state — `HasGroupedSettings::saveSettingsGroup()` validated `$this->data['checkout']`
 * directly, and the group's 4 RichEditor fields are ALWAYS raw Tiptap JSON documents there
 * (`RichEditorStateCast::set()` always returns `getDocument()`), never the HTML string the
 * `['nullable', 'string', ...]` rule expects — so `ValidationException` was thrown before
 * `persistSettingsGroup()` ever ran. Fixed by `HasGroupedSettings::getGroupStateFromComponents()`
 * (builds each group's data from its own field components' `getState()`, which applies that
 * field's StateCasts — see `filament-settings-pages.md` and
 * `SystemSettingsCheckoutTabSaveTest` for the full mechanism). This test now drives the REAL
 * save path instead of invoking `persistSettingsGroup()` via reflection.
 */
class SystemSettingsCheckoutOfflineDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_saving_checkout_tab_without_touching_offline_toggle_keeps_offline_settlement_enabled(): void
    {
        config([
            'przelewy24.merchant_id' => null,
            'przelewy24.reports_key' => null,
            'przelewy24.crc' => null,
        ]);

        $org = Organization::factory()->equipmentRental()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        $this->actingAs($admin);

        // Filament::setTenant(), NOT $this->app['request']->attributes->set('tenant', ...):
        // Livewire::test() rebinds the `request` container singleton to a new Request instance
        // when mounting a full-page component, which drops any attribute set on the pre-mount
        // request before TenantFeature::currentTenant()'s request-fallback branch ever runs.
        // Filament::setTenant() writes to the `filament` container-scoped singleton instead,
        // which survives that swap. See SystemSettingsCheckoutTabSaveTest's docblock for the
        // full empirical trace.
        Filament::setTenant($org);

        // The organization has NO `checkout.settlement_offline_enabled` row at all —
        // exactly the state every tenant is in until someone explicitly toggles it.
        $this->assertDatabaseMissing('settings', [
            'organization_id' => $org->id,
            'group' => 'checkout',
            'key' => 'settlement_offline_enabled',
        ]);

        Livewire::test(SystemSettings::class)
            ->call('saveCheckoutSettings')
            ->assertHasNoErrors();

        $settingsManager = app(SettingsManager::class);

        $this->assertTrue($settingsManager->isOfflineSettlementEnabled());
        $this->assertContains('offline', $settingsManager->availableSettlementMethods());
    }
}
