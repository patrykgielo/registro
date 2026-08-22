<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SystemSettings;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins the fix for the checkout tab being unsaveable for EVERY tenant, in EVERY state,
 * even completely untouched — found 2026-08-22, see
 * `.claude/rules/filament-settings-pages.md` → "RichEditor w grupie z HasGroupedSettings".
 *
 * Root cause: `HasGroupedSettings::saveSettingsGroup()` validated `$this->data[$group]`
 * directly. For a RichEditor field, that raw Livewire property is ALWAYS the internal
 * Tiptap JSON document (`RichEditorStateCast::set()` always returns `getDocument()`), never
 * the HTML string the `['nullable', 'string', ...]` rule expects — so `saveCheckoutSettings()`
 * threw `ValidationException` on every save, for every tenant, on the 4 RichEditor fields
 * (`terms_label`, `rodo_label`, `withdrawal_label`, `deposit_policy_note`), before
 * `persistSettingsGroup()` ever ran.
 *
 * Fix: `HasGroupedSettings::getGroupStateFromComponents()` builds the group's data from each
 * field COMPONENT's own `getState()` (Filament\Schemas\Components\Concerns\HasState::getState(),
 * verified in vendor/filament/schemas/src/Components/Concerns/HasState.php:934) instead of the
 * raw `$this->data[$group]` array. That method applies the field's own StateCasts to the raw
 * Livewire state — for RichEditor, `RichEditorStateCast::get()` (vendor/filament/forms/src/
 * Components/RichEditor/StateCasts/RichEditorStateCast.php) renders the Tiptap document back to
 * HTML (`isJson()` is false here — SystemSettings is not Eloquent-backed) — WITHOUT going
 * through `$this->form->getState()`, which would validate every field on every tab (the thing
 * this trait exists to avoid, see "CRITICAL: Problem z $this->form->getState()").
 *
 * Tenant binding: `Filament::setTenant($org)`, NOT `$this->app['request']->attributes->set(
 * 'tenant', $org)` used elsewhere in this suite (e.g. the sibling
 * SystemSettingsCheckoutOfflineDefaultTest, RentalAvailabilityGuardTest,
 * ServiceResourceTenantLabelTest). Found empirically while writing this test:
 * `Livewire::test(SystemSettings::class)` dispatches an actual sub-request through the HTTP
 * kernel to mount a full-page Livewire component, which rebinds the `request` container
 * singleton to a NEW `Request` instance — verified via `spl_object_id(app('request'))` changing
 * across the `Livewire::test(...)` call. Any attribute set on the pre-mount request object is
 * gone by the time `TenantFeature::currentTenant()`'s request-fallback branch runs, and — since
 * this panel has no `->tenant()` configured in AdminPanelProvider — `filament()->getTenant()`
 * would otherwise also return null, so every write from a real `->call()` silently lands on the
 * organization_id=NULL (global) row instead of the tenant's own row. `Filament::setTenant()`
 * writes to `FilamentManager`, bound via `$this->app->scoped('filament', ...)` — a container
 * scope that survives the request-instance swap (only `app()->forgetScopedInstances()`/request
 * termination clears it), so it's visible to `filament()->getTenant()` — step 1 of
 * `TenantFeature::currentTenant()` — both before and after the mount/call. The other tests using
 * the request-attribute form either never call `Livewire::test()` at all (direct static calls),
 * or only assert "does not throw", not tenant-scoped persistence — so the same gap in those is
 * currently latent, not yet load-bearing. Worth revisiting there separately.
 */
class SystemSettingsCheckoutTabSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsAdminFor(Organization $org): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        $this->actingAs($admin);

        // See class docblock: Filament::setTenant(), not the request-attribute form, is
        // required to survive Livewire::test()'s request-object swap.
        Filament::setTenant($org);
    }

    public function test_saving_untouched_checkout_tab_succeeds_and_persists_rich_editor_fields_as_html_strings(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->actingAsAdminFor($org);

        $this->assertDatabaseMissing('settings', [
            'organization_id' => $org->id,
            'group' => 'checkout',
            'key' => 'terms_label',
        ]);

        Livewire::test(SystemSettings::class)
            ->call('saveCheckoutSettings')
            ->assertHasNoErrors();

        foreach (['terms_label', 'rodo_label', 'withdrawal_label', 'deposit_policy_note'] as $key) {
            $row = Setting::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('group', 'checkout')
                ->where('key', $key)
                ->first();

            $this->assertNotNull($row, "Expected a persisted row for checkout.{$key}");

            $stored = $row->value[0] ?? null;

            $this->assertIsString($stored, "checkout.{$key} should be stored as a string, got: ".var_export($stored, true));
            $this->assertStringContainsString('<p', $stored, "checkout.{$key} should be an HTML fragment, got: {$stored}");
        }
    }

    public function test_saving_checkout_tab_persists_a_real_content_change_for_rich_editor_field(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->actingAsAdminFor($org);

        $uniqueMarker = 'UNIQMARK-'.uniqid();

        $newDocument = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => "Akceptuję regulamin {$uniqueMarker}"],
                    ],
                ],
            ],
        ];

        Livewire::test(SystemSettings::class)
            ->set('data.checkout.terms_label', $newDocument)
            ->call('saveCheckoutSettings')
            ->assertHasNoErrors();

        $persisted = app(SettingsManager::class)->get('checkout.terms_label');

        $this->assertIsString($persisted);
        $this->assertStringContainsString($uniqueMarker, $persisted);
    }
}
