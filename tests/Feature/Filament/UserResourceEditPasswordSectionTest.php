<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Notifications\AdminCreatedUserNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ClickUp 123k99ct40d — the "Hasło" section rendered on Edit too, with no
 * ->visibleOn('create')/->hiddenOn('edit'). Its send_setup_email checkbox is
 * virtual (no column on `users`), so ->default(true) — which only applies on
 * Create — left it `null` on Edit, and `!null === true` made both password
 * fields ->required(), even though they're also ->disabled(). Saving ANY
 * existing user without touching anything failed validation on two fields
 * nobody could fill in. Fix: the whole section is create-only now; editing a
 * user with no password no longer offers to set one from the edit form at
 * all — that gap is covered by extending the existing resend_password_setup
 * row action (previously ->visible() only for password===null accounts) to
 * every account, not just passwordless ones.
 */
class UserResourceEditPasswordSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsSuperAdmin(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('super-admin');
        $this->actingAs($operator);

        return $operator;
    }

    /**
     * The exact bug report: open an existing user, click Save, nothing
     * touched. Must succeed and must not change a single attribute —
     * `fresh()` diff, not just "no validation error", is what actually
     * pins this (see filament-resources.md "Kształt komponentu MUSI
     * odpowiadać kształtowi w bazie" for why the weaker assertion lies).
     */
    public function test_saving_an_existing_user_with_no_changes_succeeds_and_changes_nothing(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create();
        $target->assignRole('staff');

        $before = $target->fresh()->getAttributes();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $target->fresh()->getAttributes();

        $this->assertSame($before, $after);
    }

    /**
     * The "Hasło" section is ->visibleOn('create') — Filament skips rule
     * application for invisible components entirely (the same mechanism
     * filament-resources.md's "Warunkowa Walidacja: Create vs Edit" pattern
     * relies on), which is why test above passes with no fillForm() at all.
     * Pinned here directly: the password field carries no state to submit
     * on Edit (never hydrated from the record — there's nothing to hydrate,
     * it's virtual), and the checkbox — cast to bool by Filament's own
     * Checkbox component regardless of visibility — comes back false, not
     * the record's actual (irrelevant) password.
     */
    public function test_the_password_section_fields_carry_no_state_on_the_edit_form(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create();

        $component = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);

        $this->assertFalse($component->get('data.send_setup_email'));
        $this->assertNull($component->get('data.password'));
    }

    /**
     * Loosening the Create validation would reopen a way to mint a user
     * with neither a usable password nor a setup link.
     */
    public function test_creating_a_user_still_requires_a_password_or_the_send_setup_email_flag(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Jan',
                'last_name' => 'Testowy',
                'email' => 'no-password-no-flag@example.com',
                'send_setup_email' => false,
            ])
            ->call('create')
            ->assertHasFormErrors(['password', 'password_confirmation']);

        $this->assertDatabaseMissing('users', ['email' => 'no-password-no-flag@example.com']);
    }

    public function test_resend_password_setup_action_is_visible_and_works_for_a_user_without_a_password(): void
    {
        $this->actingAsSuperAdmin();
        Notification::fake();

        $target = User::factory()->create(['password' => null]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('resend_password_setup', $target)
            ->callTableAction('resend_password_setup', $target);

        Notification::assertSentTo($target, AdminCreatedUserNotification::class);
        $this->assertNotNull($target->fresh()->password_setup_token);
    }

    /**
     * The behavioral extension this task asked for: the action used to be
     * ->visible() only for password===null accounts, leaving no admin-side
     * recovery path for a user who has a password but lost it. It must now
     * work for those accounts too, with wording that says "change", not
     * "set" (see UserResource.php's label()/modalHeading() closures).
     */
    public function test_resend_password_setup_action_is_visible_and_works_for_a_user_with_a_password(): void
    {
        $this->actingAsSuperAdmin();
        Notification::fake();

        $plaintext = 'obviously-fake-test-placeholder-1';
        $target = User::factory()->create(['password' => Hash::make($plaintext)]);
        $originalHash = $target->password;

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('resend_password_setup', $target)
            ->callTableAction('resend_password_setup', $target);

        Notification::assertSentTo($target, AdminCreatedUserNotification::class);

        $fresh = $target->fresh();
        $this->assertNotNull($fresh->password_setup_token);
        // The point of the whole exercise: generating the link must not
        // touch the password that still works today.
        $this->assertSame($originalHash, $fresh->password);
        $this->assertTrue(Hash::check($plaintext, $fresh->password));
    }

    /**
     * Direct pin on the model method itself, independent of the Filament
     * action wrapper above — this is the fact the whole design decision
     * (extend resend instead of leaving the edit-form password fields
     * enabled) rests on.
     */
    public function test_initiate_password_setup_does_not_invalidate_the_current_password(): void
    {
        $plaintext = 'obviously-fake-test-placeholder-2';
        $user = User::factory()->create(['password' => Hash::make($plaintext)]);

        $user->initiatePasswordSetup();

        $this->assertTrue(Hash::check($plaintext, $user->fresh()->password));
    }
}
