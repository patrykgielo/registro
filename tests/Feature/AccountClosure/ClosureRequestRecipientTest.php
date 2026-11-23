<?php

declare(strict_types=1);

namespace Tests\Feature\AccountClosure;

use App\Enums\OrganizationLifecycleState;
use App\Filament\Pages\SystemSettings;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OrganizationClosureRequestedNotification;
use App\Notifications\OrganizationClosureWithdrawnNotification;
use App\Support\Settings\SettingsManager;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Closure request on a stack with NO super-admin.
 *
 * requestClosure() used to mail `User::role('super-admin')->get()`: on a dedicated tenant stack
 * that is an empty collection, so the flag was set, "Wniosek wysłany" was shown and the mail went
 * nowhere. The recipient is now the global `account.closure_request_email` address; with no
 * usable address the request must FAIL loudly (Log::error + danger notification) and leave the
 * flag alone, otherwise the UI shows a "pending" request nobody was ever told about.
 */
class ClosureRequestRecipientTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'operator@registro.test';

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->org = Organization::factory()->create(['owner_id' => $this->admin->id]);

        Cache::flush();

        // The dedicated-stack premise: nobody holds `super-admin`.
        $this->assertSame(0, User::role('super-admin')->count());
    }

    private function operatorAddress(?string $email): void
    {
        app(SettingsManager::class)->setGlobal('account.closure_request_email', $email ?? '');
        Cache::flush();
    }

    private function request(): void
    {
        $this->actingAs($this->admin);
        request()->attributes->set('tenant', $this->org->fresh());
        (new SystemSettings)->requestClosure();
    }

    private function withdraw(): void
    {
        $this->actingAs($this->admin);
        request()->attributes->set('tenant', $this->org->fresh());
        (new SystemSettings)->withdrawClosureRequest();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flashed(): array
    {
        // Filament 4.17 no longer keeps pending notifications in the session: they wait in a
        // session-scoped mailbox until the next full page load pulls them.
        $mailbox = app(\Filament\Notifications\NotificationMailbox::class);
        $mailbox->flush();

        return array_values((array) $mailbox->pull());
    }

    public function test_the_request_is_mailed_to_the_global_address_when_no_super_admin_exists(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);

        $this->request();

        Notification::assertSentOnDemand(
            OrganizationClosureRequestedNotification::class,
            fn ($notification, array $channels, $notifiable) => $notifiable->routes['mail'] === self::OPERATOR
        );
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        $this->assertSame('success', $this->flashed()[0]['status'] ?? null);
    }

    public function test_a_super_admin_who_happens_to_exist_is_not_the_recipient(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $super = User::factory()->create();
        $super->assignRole('super-admin');

        $this->request();

        Notification::assertNotSentTo($super, OrganizationClosureRequestedNotification::class);
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);
    }

    public function test_a_double_call_mails_once(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);

        $this->request();
        $this->request();

        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);
        $this->assertSame(1, OrganizationLifecycleLog::where('event', 'closure_requested')->count());
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unusableAddresses(): array
    {
        return [
            'no setting row at all' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'not an address' => ['kontakt-bez-malpy'],
        ];
    }

    #[DataProvider('unusableAddresses')]
    public function test_with_no_usable_recipient_the_request_fails_loudly_and_changes_nothing(?string $address): void
    {
        Notification::fake();
        Log::spy();
        if ($address !== null) {
            $this->operatorAddress($address);
        }

        $this->request();

        Log::shouldHaveReceived('error')->once();
        Notification::assertNothingSent();
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 0);

        // Nothing was told to anyone, so nothing may look "pending".
        $this->assertNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_requested')->count());

        $flashed = $this->flashed();
        $this->assertCount(1, $flashed);
        $this->assertSame('danger', $flashed[0]['status']);
        $this->assertSame(__('account_closure.no_recipient.title'), $flashed[0]['title']);
        $this->assertNotSame('Wniosek wysłany', $flashed[0]['title']);
    }

    public function test_the_user_can_retry_once_the_address_is_set(): void
    {
        Notification::fake();
        $this->request();
        $this->assertNull($this->org->fresh()->closure_requested_at);

        $this->operatorAddress(self::OPERATOR);
        $this->request();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);
    }

    public function test_a_tenant_scoped_row_is_not_a_recipient(): void
    {
        Notification::fake();
        Setting::withoutGlobalScope('organization')->create([
            'organization_id' => $this->org->id,
            'group' => 'account',
            'key' => 'closure_request_email',
            'value' => ['tenant-owned@example.test'],
        ]);
        Cache::flush();

        $this->request();

        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 0);
        $this->assertNull($this->org->fresh()->closure_requested_at);
    }

    // ------------------------------------------------------------------
    // Where the flag is cleared on a stack without /platform
    // ------------------------------------------------------------------

    public function test_the_tenant_admin_can_withdraw_a_pending_request(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->request();
        $this->assertNotNull($this->org->fresh()->closure_requested_at);

        $this->withdraw();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(OrganizationLifecycleState::Active, $this->org->fresh()->lifecycle_state);
        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'event' => 'closure_request_withdrawn',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_after_withdrawing_a_new_request_can_be_filed(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->request();
        $this->withdraw();

        $this->request();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 2);
    }

    public function test_withdrawing_with_nothing_pending_is_a_harmless_no_op(): void
    {
        $this->withdraw();

        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_request_withdrawn')->count());
        $this->assertSame('info', $this->flashed()[0]['status'] ?? null);
    }

    public function test_a_double_withdraw_logs_once(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->request();

        $this->withdraw();
        $this->withdraw();

        $this->assertSame(1, OrganizationLifecycleLog::where('event', 'closure_request_withdrawn')->count());
    }

    public function test_a_request_cannot_be_withdrawn_once_closing_has_started(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->request();

        $this->org->lifecycle_state = OrganizationLifecycleState::Closing;
        $this->org->save();

        $this->withdraw();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_request_withdrawn')->count());
    }

    // ------------------------------------------------------------------
    // Flag + log + mail are one unit (a failing dispatch must not leave "pending")
    // ------------------------------------------------------------------

    private function failingDispatcher(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('queue is down'));
        });
    }

    public function test_a_dispatch_that_throws_rolls_back_the_flag_and_the_log_and_says_so(): void
    {
        Log::spy();
        $this->operatorAddress(self::OPERATOR);
        $this->failingDispatcher();

        $this->request();

        Log::shouldHaveReceived('error')->once();
        $this->assertNull($this->org->fresh()->closure_requested_at, 'the flag survived a failed dispatch');
        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_requested')->count());

        $flashed = $this->flashed();
        $this->assertCount(1, $flashed);
        $this->assertSame('danger', $flashed[0]['status']);
        $this->assertSame(__('account_closure.send_failed.title'), $flashed[0]['title']);
    }

    public function test_after_a_failed_dispatch_the_request_can_be_filed_again(): void
    {
        $this->operatorAddress(self::OPERATOR);
        $this->failingDispatcher();
        $this->request();
        $this->assertNull($this->org->fresh()->closure_requested_at);

        $this->app->forgetInstance(Dispatcher::class);
        $this->app->alias(\Illuminate\Notifications\ChannelManager::class, Dispatcher::class);
        Notification::fake();

        $this->request();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // The lifecycle-state guard lives in the UPDATE itself
    // ------------------------------------------------------------------

    private function orgStateTurnsClosingAfterTheCheck(): void
    {
        $this->actingAs($this->admin);
        // The component read an Active org...
        request()->attributes->set('tenant', $this->org->fresh());
        // ...and offboarding started before the write.
        DB::table('organizations')->where('id', $this->org->id)->update(['lifecycle_state' => 'closing']);
    }

    public function test_a_request_is_refused_when_closing_starts_between_the_check_and_the_write(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->orgStateTurnsClosingAfterTheCheck();

        (new SystemSettings)->requestClosure();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_requested')->count());
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 0);
        $this->assertSame('info', $this->flashed()[0]['status'] ?? null);
    }

    public function test_a_withdrawal_is_refused_when_closing_starts_between_the_check_and_the_write(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->org->closure_requested_at = now()->subHour();
        $this->org->save();
        $this->orgStateTurnsClosingAfterTheCheck();

        (new SystemSettings)->withdrawClosureRequest();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(0, OrganizationLifecycleLog::where('event', 'closure_request_withdrawn')->count());
        Notification::assertSentOnDemandTimes(OrganizationClosureWithdrawnNotification::class, 0);
    }

    // ------------------------------------------------------------------
    // The operator hears about a withdrawal, at the same address
    // ------------------------------------------------------------------

    public function test_a_withdrawal_mails_the_operator_at_the_same_global_address(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->request();

        $this->withdraw();

        Notification::assertSentOnDemand(
            OrganizationClosureWithdrawnNotification::class,
            fn ($notification, array $channels, $notifiable) => $notifiable->routes['mail'] === self::OPERATOR
        );
        Notification::assertSentOnDemandTimes(OrganizationClosureWithdrawnNotification::class, 1);
        $this->assertSame('success', $this->flashed()[1]['status'] ?? null);
    }

    public function test_the_withdrawal_mail_is_available_in_polish_and_english(): void
    {
        $notification = new OrganizationClosureWithdrawnNotification($this->org, $this->admin);

        app()->setLocale('pl');
        $pl = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $this->assertStringContainsString('wycofany', $pl->subject);
        $this->assertStringContainsString($this->org->name, $pl->subject);
        $this->assertStringContainsString($this->admin->email, implode("\n", $pl->introLines));

        app()->setLocale('en');
        $en = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $this->assertStringContainsString('withdrawn', $en->subject);
        $this->assertStringNotContainsString('wycofany', $en->subject.implode(' ', $en->introLines));
        $this->assertStringNotContainsString('account_closure.', $en->subject.implode(' ', $en->introLines).$en->greeting.$en->salutation);
    }

    public function test_withdrawing_still_works_when_the_operator_cannot_be_told_and_says_so(): void
    {
        Log::spy();
        $this->org->closure_requested_at = now()->subHour();
        $this->org->save();
        // no recipient configured

        $this->withdraw();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        Log::shouldHaveReceived('error')->once();
        $this->assertSame('warning', $this->flashed()[0]['status'] ?? null);
    }

    public function test_withdrawing_still_works_when_the_withdrawal_mail_dispatch_throws(): void
    {
        Log::spy();
        $this->operatorAddress(self::OPERATOR);
        $this->org->closure_requested_at = now()->subHour();
        $this->org->save();
        $this->failingDispatcher();

        $this->withdraw();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        Log::shouldHaveReceived('error')->once();
        $this->assertSame('warning', $this->flashed()[0]['status'] ?? null);
    }

    // ------------------------------------------------------------------
    // Through the real Livewire page: visibility and ->authorize() are in play
    // ------------------------------------------------------------------

    private function mountSettingsPageAsAdmin(): \Livewire\Features\SupportTesting\Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        Filament::setTenant($this->org);

        return Livewire::test(SystemSettings::class);
    }

    private function withdrawAction(): TestAction
    {
        return TestAction::make('withdrawClosureRequest')->schemaComponent('konto.withdraw_closure_actions', schema: 'form');
    }

    public function test_the_withdraw_button_goes_through_livewire_and_clears_the_flag(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);
        $this->org->closure_requested_at = now()->subHour();
        $this->org->save();

        $page = $this->mountSettingsPageAsAdmin();
        $this->assertNotNull($page->instance()->getSchemaComponent('form.konto.withdraw_closure_actions'));

        $page
            ->assertActionVisible($this->withdrawAction())
            ->callAction($this->withdrawAction())
            ->assertHasNoErrors();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'event' => 'closure_request_withdrawn',
            'actor_id' => $this->admin->id,
        ]);
        Notification::assertSentOnDemandTimes(OrganizationClosureWithdrawnNotification::class, 1);
    }

    public function test_the_withdraw_button_is_hidden_when_nothing_is_pending(): void
    {
        $page = $this->mountSettingsPageAsAdmin();

        $this->assertNull($page->instance()->getSchemaComponent('form.konto.withdraw_closure_actions'));
        // a hidden button cannot be driven either
        $this->assertThrows(fn () => $page->callAction($this->withdrawAction()), ActionNotResolvableException::class);
    }

    public function test_the_withdraw_button_is_hidden_once_closing_has_started(): void
    {
        $this->org->closure_requested_at = now()->subHour();
        $this->org->lifecycle_state = OrganizationLifecycleState::Closing;
        $this->org->save();

        $page = $this->mountSettingsPageAsAdmin();

        $this->assertNull($page->instance()->getSchemaComponent('form.konto.withdraw_closure_actions'));
        $this->assertThrows(fn () => $page->callAction($this->withdrawAction()), ActionNotResolvableException::class);
        $this->assertNotNull($this->org->fresh()->closure_requested_at);
    }

    public function test_the_request_button_goes_through_livewire_and_mails_the_operator(): void
    {
        Notification::fake();
        $this->operatorAddress(self::OPERATOR);

        $this->mountSettingsPageAsAdmin()
            ->callAction(TestAction::make('requestClosure')->schemaComponent('konto.request_closure_actions', schema: 'form'));

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
        Notification::assertSentOnDemandTimes(OrganizationClosureRequestedNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // The operator mail on a stack without /platform
    // ------------------------------------------------------------------

    public function test_the_mail_has_no_dead_platform_link_on_a_dedicated_stack(): void
    {
        config(['app.tenant_slug' => 'acme']);

        $mail = (new OrganizationClosureRequestedNotification($this->org, $this->admin))
            ->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

        $this->assertNull($mail->actionUrl);
        $this->assertStringNotContainsString('/platform', implode("\n", $mail->introLines));
    }

    public function test_the_mail_keeps_the_platform_link_on_the_shared_stack(): void
    {
        config(['app.tenant_slug' => null]);

        $mail = (new OrganizationClosureRequestedNotification($this->org, $this->admin))
            ->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

        $this->assertStringContainsString('/platform/organizations/'.$this->org->id, (string) $mail->actionUrl);
    }
}
