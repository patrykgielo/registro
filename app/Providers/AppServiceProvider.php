<?php

declare(strict_types=1);

namespace App\Providers;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Events\AdminCreatedUser;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\OrderHandedOver;
use App\Events\OrderPaid;
use App\Events\OrderReturned;
use App\Events\PasswordResetRequested;
use App\Events\RentalCancelled;
use App\Events\TenantRegistered;
use App\Events\UserRegistered;
use App\Listeners\LogAuthenticationEvents;
use App\Listeners\RecordAnalyticsOnOrderPaid;
use App\Listeners\SendRentalCancelledNotification;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Page as PageModel;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\User;
use App\Notifications\AdminCreatedUserNotification;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\AppointmentCreatedNotification;
use App\Notifications\AppointmentRescheduledNotification;
use App\Notifications\NewTenantRegisteredNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderConfirmedNotification;
use App\Notifications\OrderHandedOverNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderReturnedNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\TenantWelcomeNotification;
use App\Notifications\UserRegisteredNotification;
use App\Observers\AppointmentObserver;
use App\Observers\OrganizationObserver;
use App\Observers\PageObserver;
use App\Observers\SitemapCacheObserver;
use App\Observers\UserObserver;
use App\Services\Email\EmailGatewayInterface;
use App\Services\Email\EmailService;
use App\Services\Email\FakeEmailGateway;
use App\Services\Email\SmtpMailer;
use App\Services\MaintenanceService;
use App\Services\Sms\SmsApiGateway;
use App\Services\Sms\SmsGatewayInterface;
use App\Services\Sms\SmsService;
use App\Support\Settings\SettingsManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register SettingsManager as singleton
        $this->app->singleton(SettingsManager::class);

        // Register MaintenanceService as singleton
        $this->app->singleton(MaintenanceService::class);

        // Bind EmailGateway interface to appropriate implementation
        // Use FakeEmailGateway in testing to prevent actual SMTP connections
        if ($this->app->environment('testing')) {
            $this->app->bind(EmailGatewayInterface::class, FakeEmailGateway::class);
        } else {
            $this->app->bind(EmailGatewayInterface::class, SmtpMailer::class);
        }

        // Register EmailService as singleton
        $this->app->singleton(EmailService::class);

        // Bind SmsGateway interface to SMSAPI implementation
        $this->app->bind(SmsGatewayInterface::class, SmsApiGateway::class);

        // Register SmsService as singleton
        $this->app->singleton(SmsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Appointment::observe(AppointmentObserver::class);
        Organization::observe(OrganizationObserver::class);
        PageModel::observe(PageObserver::class);
        PageModel::observe(SitemapCacheObserver::class);
        Post::observe(SitemapCacheObserver::class);
        PortfolioItem::observe(SitemapCacheObserver::class);
        User::observe(UserObserver::class);

        // Override mail configuration with database settings
        $this->configureMailFromDatabase();

        // Register event listeners for email notifications
        $this->registerEventListeners();

        // Register GDPR audit logging for authentication events
        Event::subscribe(LogAuthenticationEvents::class);

        // Share global feature flags with all Blade views
        $this->shareFeatureFlags();

        // Share brand design variables with mail views
        $this->shareMailBrandVariables();

        // Register analytics rate limiter — per-IP bucket + per-tenant burst guard
        RateLimiter::for('analytics', function (Request $request): array {
            /** @var \App\Models\Organization|null $tenant */
            $tenant = $request->attributes->get('tenant');

            $limits = [Limit::perMinute(120)->by($request->ip())];

            if ($tenant) {
                $limits[] = Limit::perMinute(600)->by('analytics-tenant:'.$tenant->id);
            }

            return $limits;
        });

        // Inject $pageType into frontend layout for analytics tracking
        view()->composer('layouts.app', \App\View\Composers\PageTypeComposer::class);

        // Livewire admin/platform tenant isolation — see
        // app/docs/security/patterns/livewire-tenant-isolation.md
        $this->registerLivewireTenantIsolation();
    }

    /**
     * Close the cross-tenant leak on POST /livewire/update (Livewire's own shared
     * AJAX endpoint, registered with only the base 'web' middleware — it never runs
     * ResolveTenant/RequireTenant, so almost all real /admin interaction (table
     * loads, filters, form saves) resolved the tenant from `session('tenant_id')`
     * alone, which ResolveTenant overwrites on ANY successful subdomain visit by
     * the same browser (even an unrelated tab, even anonymous) — a poisoned
     * session let a staff/admin user with an open Org A admin tab silently read
     * and write Org B's data via ordinary Livewire interactions.
     *
     * Fix uses Livewire 3's own `PersistentMiddleware` mechanism (already shipped
     * for exactly this class of problem — Sanctum/Jetstream auth middleware are
     * in its default allow-list). Every Livewire component's snapshot carries a
     * tamper-proof `memo.path`/`memo.method` — the URL that ORIGINALLY mounted it
     * (set at full-page-load time, verified by Livewire's own checksum before our
     * code ever sees it). On every subsequent /livewire/update call, Livewire
     * builds a fake request with that original path/method (but the REAL,
     * current request's Host header, cookies and session) and replays whichever
     * of the *matched route's* middleware are in this allow-list.
     *
     * Adding ResolveTenant + RequireTenant here means:
     * - A component mounted under /admin/* replays ResolveTenant against the
     *   REAL Host header of the tab making the AJAX call (never the stale
     *   session), re-deriving the correct tenant and re-running the
     *   canAccessTenant() staff-authorization check — then OVERWRITES
     *   session('tenant_id') with the correct value before Filament resolves
     *   any BelongsToOrganization-scoped query for this update.
     * - A component mounted under /platform/* never matches a route carrying
     *   these two middleware (PlatformPanelProvider never registers them), so
     *   the replay is a no-op — platform Livewire traffic is completely
     *   unaffected, exactly as today.
     *
     * See the doc above for the full design/rationale, rejected alternatives,
     * and residual limitations.
     */
    private function registerLivewireTenantIsolation(): void
    {
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\RequireTenant::class,
        ]);
    }

    /**
     * Share global feature flags with all Blade views.
     *
     * Provides $bookingEnabled, $rentalEnabled, $registrationEnabled, $contactPhone,
     * $contactEmail for conditional rendering of CTAs, cart links, and registration
     * links.
     */
    private function shareFeatureFlags(): void
    {
        view()->composer('*', function ($view) {
            $sm = app(SettingsManager::class);
            $view->with('bookingEnabled', $sm->isBookingEnabled());
            $view->with('rentalEnabled', $sm->isRentalEnabled());
            $view->with('registrationEnabled', $sm->isRegistrationEnabled());
            $view->with('contactPhone', $sm->get('contact.phone', ''));
            // Used on the root domain (no tenant resolved) as the fallback
            // call-to-action where a "sign up" link used to be -- there is no
            // public self-serve registration anymore, see routes/web.php.
            $view->with('contactEmail', $sm->contactInformation()['email'] ?? 'kontakt@registro.app');
        });
    }

    /**
     * Share brand design variables with mail views.
     *
     * Injects $brandColor, $brandName, and $logoUrl into vendor mail templates
     * so transactional emails can use tenant brand color and logo.
     *
     * Only injects values when the tenant has email branding enabled.
     * Skipped in testing environment to avoid DB hits during mail tests.
     */
    private function shareMailBrandVariables(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        view()->composer('vendor.mail.*', function ($view) {
            try {
                $sm = app(SettingsManager::class);

                $brandColor = $sm->useColorInEmails() ? $sm->brandColor() : null;
                $logoUrl = $sm->useLogoInEmails() ? $sm->headerLogo() : null;
                $brandName = $sm->brandName();

                $view->with('brandColor', $brandColor);
                $view->with('logoUrl', $logoUrl);
                $view->with('brandName', $brandName);
            } catch (\Throwable) {
                // Fail silently — mail must not be blocked by a settings error
            }
        });
    }

    /**
     * Override runtime mail configuration with settings from database.
     *
     * This allows dynamic SMTP configuration without modifying .env file.
     * Only applies if smtp_host is set in database settings.
     * Skipped in testing environment to prevent SMTP configuration.
     */
    private function configureMailFromDatabase(): void
    {
        // Skip in testing environment
        if ($this->app->environment('testing')) {
            return;
        }

        try {
            $settingsManager = app(SettingsManager::class);
            $emailSettings = $settingsManager->group('email');

            // Only override if SMTP host is configured
            if (! empty($emailSettings['smtp_host'])) {
                config([
                    'mail.mailers.smtp.host' => $emailSettings['smtp_host'],
                    'mail.mailers.smtp.port' => $emailSettings['smtp_port'] ?? 587,
                    'mail.mailers.smtp.encryption' => $emailSettings['smtp_encryption'] ?? 'tls',
                    'mail.mailers.smtp.username' => $emailSettings['smtp_username'] ?? null,
                    'mail.mailers.smtp.password' => $emailSettings['smtp_password'] ?? null,
                    'mail.from.address' => $emailSettings['from_address'] ?? config('mail.from.address'),
                    'mail.from.name' => $emailSettings['from_name'] ?? config('mail.from.name'),
                ]);
            }
        } catch (\Exception $e) {
            // Fail silently during migrations or if settings table doesn't exist yet
            // This prevents errors during initial setup
        }
    }

    /**
     * Register event listeners for email notifications.
     *
     * Maps domain events to notification dispatchers.
     */
    private function registerEventListeners(): void
    {
        // User Registration (end CUSTOMER creating an account on a tenant's site)
        Event::listen(UserRegistered::class, function (UserRegistered $event) {
            $event->user->notify(new UserRegisteredNotification($event->user));
        });

        // Tenant Registration (a BUSINESS signing up for this installation).
        //
        // Two recipients, deliberately: the owner needs their panel address --
        // without it, closing the browser loses the only route back -- and the
        // operator needs to know a tenant appeared at all, since nothing else
        // tells them.
        Event::listen(TenantRegistered::class, function (TenantRegistered $event) {
            $event->owner->notify(new TenantWelcomeNotification($event->organization));

            $operatorEmail = app(SettingsManager::class)->getGlobal('platform.new_tenant_notification_email');

            // Falls back to the closure-request address so a fresh install is
            // not silently unmonitored; empty means the operator opted out.
            if (! is_string($operatorEmail) || trim($operatorEmail) === '') {
                $fallback = app(SettingsManager::class)->getGlobal('account.closure_request_email');
                $operatorEmail = is_string($fallback) ? $fallback : '';
            }

            if (trim((string) $operatorEmail) !== '') {
                Notification::route(EmailServiceChannel::class, trim((string) $operatorEmail))
                    ->notify(new NewTenantRegisteredNotification($event->organization, $event->owner));
            }
        });

        // Password Reset
        Event::listen(PasswordResetRequested::class, function (PasswordResetRequested $event) {
            $event->user->notify(new PasswordResetNotification($event->user, $event->token));
        });

        // Admin Created User (password setup email)
        Event::listen(AdminCreatedUser::class, function (AdminCreatedUser $event) {
            $event->user->notify(new AdminCreatedUserNotification($event->user));
        });

        // Appointment Created
        Event::listen(AppointmentCreated::class, function (AppointmentCreated $event) {
            // Notify customer
            $event->appointment->customer->notify(
                new AppointmentCreatedNotification($event->appointment, 'customer')
            );
        });

        // Appointment Rescheduled
        Event::listen(AppointmentRescheduled::class, function (AppointmentRescheduled $event) {
            $event->appointment->customer->notify(
                new AppointmentRescheduledNotification(
                    $event->appointment,
                    $event->oldDate,
                    $event->newDate,
                    'staff'
                )
            );
        });

        // Appointment Cancelled
        Event::listen(AppointmentCancelled::class, function (AppointmentCancelled $event) {
            $event->appointment->customer->notify(
                new AppointmentCancelledNotification($event->appointment, $event->reason)
            );
        });

        // ========== ORDER NOTIFICATIONS ==========

        // Order Paid: notify customer + admin/org owner
        Event::listen(OrderPaid::class, function (OrderPaid $event) {
            $order = $event->order->load(['user', 'organization.owner']);

            if ($order->user) {
                $order->user->notify(new OrderPaidNotification($order, 'customer'));
            } else {
                \Log::warning('OrderPaid: no user attached, skipping customer notification', [
                    'order_id' => $order->id,
                ]);
            }

            if ($order->organization?->owner) {
                $order->organization->owner->notify(new OrderPaidNotification($order, 'admin'));
            }
        });

        // Order Confirmed: notify customer
        Event::listen(OrderConfirmed::class, function (OrderConfirmed $event) {
            $order = $event->order->load('user');

            if ($order->user) {
                $order->user->notify(new OrderConfirmedNotification($order));
            } else {
                \Log::warning('OrderConfirmed: no user attached, skipping customer notification', [
                    'order_id' => $order->id,
                ]);
            }
        });

        // Order Cancelled: notify customer (unless this was an internal
        // compensation cancel, e.g. P24 registration failure right after
        // checkout — see OrderService::cancel($order, $reason, notify: false))
        Event::listen(OrderCancelled::class, function (OrderCancelled $event) {
            if (! $event->notify) {
                \Log::info('OrderCancelled: notify=false, skipping customer notification (internal compensation)', [
                    'order_id' => $event->order->id,
                ]);

                return;
            }

            $order = $event->order->load('user');

            if ($order->user) {
                $order->user->notify(new OrderCancelledNotification($order, $event->reason));
            } else {
                \Log::warning('OrderCancelled: no user attached, skipping customer notification', [
                    'order_id' => $order->id,
                ]);
            }
        });

        // Order Handed Over: notify customer ("Wydano klientowi" admin action)
        Event::listen(OrderHandedOver::class, function (OrderHandedOver $event) {
            $order = $event->order->load('user');

            if ($order->user) {
                $order->user->notify(new OrderHandedOverNotification($order));
            } else {
                \Log::warning('OrderHandedOver: no user attached, skipping customer notification', [
                    'order_id' => $order->id,
                ]);
            }
        });

        // Order Returned: notify customer ("Sprzęt zwrócony" admin action)
        Event::listen(OrderReturned::class, function (OrderReturned $event) {
            $order = $event->order->load('user');

            if ($order->user) {
                $order->user->notify(new OrderReturnedNotification($order));
            } else {
                \Log::warning('OrderReturned: no user attached, skipping customer notification', [
                    'order_id' => $order->id,
                ]);
            }
        });

        // Order Paid: record analytics
        Event::listen(OrderPaid::class, RecordAnalyticsOnOrderPaid::class);

        // ========== RENTAL NOTIFICATIONS ==========

        // Rental Cancelled: notify customer
        Event::listen(RentalCancelled::class, SendRentalCancelledNotification::class);

        // ========== SECURITY: SESSION REGENERATION ==========

        // Force session regeneration on EVERY login (prevents session fixation attacks)
        Event::listen(Login::class, function (Login $event) {
            request()->session()->regenerate();
            \Log::info('Session regenerated after login', [
                'user' => $event->user->email,
                'guard' => $event->guard,
                'ip' => request()->ip(),
            ]);
        });

        // ========== SMS NOTIFICATIONS ==========

        // Send booking confirmation SMS when customer creates appointment
        Event::listen(AppointmentCreated::class, function (AppointmentCreated $event) {
            $this->sendSmsNotification(
                TemplateKey::APPOINTMENT_CREATED->value,
                $event->appointment,
                'send_booking_confirmation'
            );
        });

        // Send admin confirmation SMS when admin confirms appointment
        Event::listen(AppointmentConfirmed::class, function (AppointmentConfirmed $event) {
            $this->sendSmsNotification(
                TemplateKey::APPOINTMENT_CONFIRMED->value,
                $event->appointment,
                'send_admin_confirmation'
            );
        });

        // Send cancellation SMS when appointment is cancelled
        Event::listen(AppointmentCancelled::class, function (AppointmentCancelled $event) {
            $this->sendSmsNotification(
                TemplateKey::APPOINTMENT_CANCELLED->value,
                $event->appointment,
                'send_cancellation' // Will use default true if not set
            );
        });

        // Send rescheduled SMS when appointment is rescheduled
        Event::listen(AppointmentRescheduled::class, function (AppointmentRescheduled $event) {
            $this->sendSmsNotification(
                TemplateKey::APPOINTMENT_RESCHEDULED->value,
                $event->appointment,
                'send_rescheduled' // Will use default true if not set
            );
        });
    }

    /**
     * Send SMS notification for appointment event.
     *
     * @param  string  $templateKey  Template key (e.g., 'appointment-created')
     * @param  \App\Models\Appointment  $appointment  The appointment
     * @param  string  $settingKey  Setting key to check if enabled (e.g., 'send_booking_confirmation')
     */
    private function sendSmsNotification(string $templateKey, $appointment, string $settingKey): void
    {
        try {
            $smsService = app(SmsService::class);
            $settingsManager = app(SettingsManager::class);
            $smsSettings = $settingsManager->group('sms');

            // Check if SMS globally enabled
            if (! ($smsSettings['enabled'] ?? true)) {
                return;
            }

            // Check if specific notification type is enabled
            if (! ($smsSettings[$settingKey] ?? true)) {
                return;
            }

            // Get phone number - prefer appointment.phone (captured at booking time)
            // Fall back to customer.phone if appointment doesn't have one
            $customerPhone = $appointment->phone ?? $appointment->customer?->phone ?? null;
            if (! $customerPhone) {
                \Log::warning('Cannot send SMS notification: no phone number available', [
                    'appointment_id' => $appointment->id,
                    'template_key' => $templateKey,
                ]);

                return;
            }

            // Get customer name - prefer appointment fields (captured at booking time)
            $customerName = trim(($appointment->first_name ?? '').' '.($appointment->last_name ?? ''));
            if (empty($customerName)) {
                $customerName = $appointment->customer?->name ?? 'Klient';
            }

            // Get customer preferred language
            $language = $appointment->customer?->preferred_language ?? 'pl';

            // Prepare template data
            $data = [
                'customer_name' => $customerName,
                'service_name' => $appointment->service?->name ?? 'N/A',
                'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
                'appointment_time' => $appointment->start_time->format('H:i'),
                'location_address' => $appointment->location_address ?? 'N/A',
                'app_name' => $settingsManager->appName(),
                'contact_phone' => $settingsManager->get('contact.phone', ''),
            ];

            // Send SMS
            $smsService->sendFromTemplate(
                $templateKey,
                $language,
                $customerPhone,
                $data,
                ['appointment_id' => $appointment->id]
            );

            \Log::info('SMS notification sent successfully', [
                'template_key' => $templateKey,
                'appointment_id' => $appointment->id,
                'phone' => substr($customerPhone, 0, 3).'***', // Masked for privacy
            ]);
        } catch (\Exception $e) {
            // Log error but don't throw - SMS failure shouldn't block appointment flow
            \Log::error('Failed to send SMS notification', [
                'template_key' => $templateKey,
                'appointment_id' => $appointment->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
