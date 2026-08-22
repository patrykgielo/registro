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
use ReflectionMethod;
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
 * for the offline-settlement default, reported separately. The true structural fix lives in
 * mount()/all()/persistSettingsGroup(), which this task was explicitly told not to touch.
 *
 * BLOCKED PATH, DOCUMENTED HERE — DO NOT "FIX" BY WEAKENING THIS TEST:
 * The literal real-UI path (Livewire::test(...)->call('saveCheckoutSettings')) currently
 * cannot be exercised at all, for ANY tenant, in ANY state — found as a side effect of writing
 * this test, independent of the bugs above, NOT fixed here (out of scope).
 * HasGroupedSettings::saveSettingsGroup() validates $this->data['checkout'] directly, bypassing
 * Filament's own state-casting pipeline (deliberately — see filament-settings-pages.md on why
 * it avoids $this->form->getState()). The checkout group also has 4 RichEditor fields
 * (terms_label, rodo_label, withdrawal_label, deposit_policy_note) whose internal
 * $this->data representation is ALWAYS the raw Tiptap JSON document
 * (RichEditorStateCast::set() always returns getDocument(), applied by Livewire's normal
 * property sync on every mount/set) — never the HTML string the
 * `['nullable', 'string', 'max:...']` rule expects. So those 4 fields fail validation on EVERY
 * save of this tab, for every tenant, even completely untouched — saveCheckoutSettings() always
 * throws ValidationException before persistSettingsGroup() ever runs. This should be reported
 * and fixed separately; it is more severe than the bugs this test guards (it currently makes
 * every concrete "silent corruption" scenario above unreachable through the real UI too, since
 * nothing ever gets past validation to persist).
 *
 * To isolate the offline-toggle regression from that unrelated, currently-blocking bug, this
 * test drives mount()/fill() for real (Livewire::test(SystemSettings::class)), then invokes the
 * REAL, unmodified HasGroupedSettings::persistSettingsGroup() via reflection with the REAL,
 * unmodified post-mount $this->data['checkout'] — i.e. it skips only the validation GATE that
 * currently (accidentally) blocks this path, not the persistence mechanism itself. That
 * mechanism — "write every key present in $data[group], including whatever mount() actually
 * produced for absent DB rows" — is exactly what this test guards.
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
        $this->app['request']->attributes->set('tenant', $org);

        // The organization has NO `checkout.settlement_offline_enabled` row at all —
        // exactly the state every tenant is in until someone explicitly toggles it.
        $this->assertDatabaseMissing('settings', [
            'organization_id' => $org->id,
            'group' => 'checkout',
            'key' => 'settlement_offline_enabled',
        ]);

        $component = Livewire::test(SystemSettings::class);
        $page = $component->instance();

        // Real, unmodified post-mount state — exactly what persistSettingsGroup() would
        // receive from a real save. See the class docblock for why we invoke it directly
        // instead of going through saveCheckoutSettings()'s (currently broken) validation gate.
        $groupData = $page->data['checkout'];

        $this->assertArrayHasKey(
            'settlement_offline_enabled',
            $groupData,
            'mount() should have populated this key for a tenant with no row of its own.'
        );
        $this->assertTrue(
            $groupData['settlement_offline_enabled'],
            'The Toggle\'s afterStateHydrated() should have detected "no row at all" via '
            .'SettingsManager and forced true. Got: '.var_export($groupData['settlement_offline_enabled'], true)
        );

        $persist = new ReflectionMethod($page, 'persistSettingsGroup');
        $persist->setAccessible(true);
        $persist->invoke($page, 'checkout', $groupData);

        $settingsManager = app(SettingsManager::class);

        $this->assertTrue($settingsManager->isOfflineSettlementEnabled());
        $this->assertContains('offline', $settingsManager->availableSettlementMethods());
    }
}
