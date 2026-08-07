<?php

use App\Http\Controllers\Api\SmsApiIncomingController;
use App\Http\Controllers\Api\SmsApiWebhookController;
use App\Http\Controllers\Api\VehicleDataController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\BusinessRegisterController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Dev\FakePaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\RentalBookingController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\RentalExtensionController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\UserAddressController;
use App\Http\Controllers\UserVehicleController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\CheckBookingEnabled;
use App\Http\Middleware\CheckRegistrationEnabled;
use App\Http\Middleware\CheckRentalEnabled;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Public home route — deliberate exception to the RequireTenant pattern below.
// On the root domain (no subdomain) ResolveTenant sets no `tenant` attribute by
// design ("marketplace, no tenant context"). RequireTenant would hard-404 that,
// but this route already has graceful no-page fallbacks (home-fallback view) for
// exactly this case. Do NOT add RequireTenant::class back here.
//
// IMPORTANT: gate on the `tenant` REQUEST ATTRIBUTE directly, not on
// SettingsManager::get()/Page::find() (which resolve "current tenant" via
// TenantFeature::currentTenant()). currentTenant() has a 3rd fallback branch
// reading session('tenant_id'), which ResolveTenant writes on EVERY subdomain
// visit — including anonymous ones. A visitor who merely browsed orgB's
// subdomain earlier in the same browser session and then hits the root
// domain would otherwise have orgB's own homepage setting/page resolved and
// rendered on the root domain (VULN-003 Layer 1/2 gap). There is no
// legitimate "global marketplace homepage" built yet, so on a true root-
// domain request (no tenant attribute) we skip the tenant-aware lookup
// entirely and always render home-fallback.
Route::middleware([ResolveTenant::class])->get('/', function (\Illuminate\Http\Request $request) {
    if (! $request->attributes->get('tenant')) {
        return view('home-fallback');
    }

    $settingsManager = app(\App\Support\Settings\SettingsManager::class);
    $pageId = $settingsManager->get('cms.homepage_page_id');

    if (! $pageId) {
        return view('home-fallback');
    }

    $page = \App\Models\Page::find($pageId);

    if (! $page || ! $page->isPublished()) {
        return view('home-fallback');
    }

    return view('pages.show', [
        'page' => $page,
        'layout' => $page->layout,
        ...\App\Support\Seo\MetaTagBuilder::forModel($page),
    ]);
})->name('home');

// Health check endpoint (for CI/CD deployment verification)
Route::get('/health', function () {
    try {
        // Check database connection
        DB::connection()->getPdo();

        // Check Redis connection
        Cache::store('redis')->get('health-check-probe');

        // Check critical services
        $checks = [
            'database' => DB::connection()->getPdo() !== null,
            'redis' => Cache::store('redis')->connection()->ping(),
        ];

        $allHealthy = collect($checks)->every(fn ($status) => $status === true || $status === 'PONG');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', 'unknown'),
        ], $allHealthy ? 200 : 503);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})->name('health');

// CMS Content routes - Posts, Promotions, Portfolio (with prefixes)
// ResolveTenant needed for BelongsToOrganization scope on content models
Route::middleware([ResolveTenant::class, RequireTenant::class])->group(function () {
    Route::get('/aktualnosci/kategoria/{category:slug}', [PostController::class, 'category'])->name('post.category');
    Route::get('/aktualnosci/{slug}', [PostController::class, 'show'])->name('post.show');
    Route::get('/promocje/{slug}', [PromotionController::class, 'show'])->name('promotion.show');
    Route::get('/portfolio/kategoria/{category:slug}', [PortfolioController::class, 'category'])->name('portfolio.category');
    Route::get('/portfolio/{slug}', [PortfolioController::class, 'show'])->name('portfolio.show');
});

// Legacy redirect: /strona/{slug} -> /{slug} (SEO 301 permanent redirect)
Route::get('/strona/{slug}', function (string $slug) {
    return redirect()->route('page.show', $slug, 301);
})->name('page.legacy');

// Sitemap (per-tenant) — queries tenant-owned content models (Page/Post/PortfolioItem/Service),
// MUST carry RequireTenant (VULN-003) right after ResolveTenant, same as every other content route.
Route::middleware([ResolveTenant::class, RequireTenant::class])
    ->get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

// Service Pages routes (P0: SEO-friendly Polish URLs with rate limiting)
Route::middleware([ResolveTenant::class, RequireTenant::class, 'throttle:60,1'])->group(function () {
    Route::get('/uslugi', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/uslugi/{service:slug}', [ServiceController::class, 'show'])->name('service.show');
});

// Service inquiry (price-on-request contact form)
Route::post('/uslugi/{service:slug}/zapytaj', [\App\Http\Controllers\ServiceInquiryController::class, 'store'])
    ->middleware([ResolveTenant::class, RequireTenant::class, 'throttle:5,1'])
    ->name('service.inquiry');

// Rental Catalogue routes (public, tenant-scoped)
Route::middleware([ResolveTenant::class, RequireTenant::class, 'throttle:60,1'])->group(function () {
    Route::get('/wypozyczalnia', [RentalController::class, 'index'])->name('rental.index');
    Route::get('/wypozyczalnia/{category:slug}', [RentalController::class, 'showCategory'])->name('rental.category');
});

// Rental availability AJAX endpoints (read-only, higher rate limit)
Route::middleware([ResolveTenant::class, RequireTenant::class, 'throttle:60,1'])->name('rental.')->group(function () {
    Route::get('/api/rental/{service:slug}/dostepnosc', [RentalBookingController::class, 'checkAvailability'])
        ->name('availability');
    Route::get('/api/rental/{service:slug}/kalendarz', [RentalBookingController::class, 'monthlyAvailability'])
        ->name('calendar');
});

// Cart & Checkout routes (Sprint 2+ — new e-commerce flow, requires auth + tenant)
// RequireTenant right after ResolveTenant (VULN-003 Layer 4) — abort_unless($org !== null)
// alone in Cart/Checkout/OrderController is not enough: TenantFeature::currentTenant()'s
// session fallback resolves a stale tenant on the root domain even without RequireTenant.
Route::middleware([ResolveTenant::class, RequireTenant::class, 'auth', CheckRentalEnabled::class])->group(function () {
    Route::get('/koszyk', [CartController::class, 'show'])->name('cart.show');
    Route::post('/koszyk/dodaj', [CartController::class, 'add'])->name('cart.add');
    Route::delete('/koszyk/usun/{item}', [CartController::class, 'remove'])->name('cart.remove');
    Route::patch('/koszyk/ilosc/{item}', [CartController::class, 'updateQuantity'])->name('cart.update');
    Route::get('/koszyk/zamowienie', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/koszyk/zamowienie', [CheckoutController::class, 'submit'])
        ->middleware('throttle:10,1')
        ->name('checkout.submit');
    Route::get('/koszyk/powrot', [CheckoutController::class, 'return'])->name('checkout.return');
    Route::get('/moje-zamowienia', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/moje-zamowienia/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/moje-zamowienia/{order}/anuluj', [OrderController::class, 'cancel'])->name('orders.cancel');

    // Rental extension requests
    Route::get('/api/zamowienia/{order}/pozycje/{orderItem}/sprawdz-przedluzenie', [RentalExtensionController::class, 'checkAvailability'])
        ->name('orders.extension.check')
        ->middleware('throttle:20,1,rental-extension-check');
    Route::post('/moje-zamowienia/{order}/pozycje/{orderItem}/przedluz', [RentalExtensionController::class, 'store'])
        ->name('orders.extension.store')
        ->middleware('throttle:3,1,rental-extension-store');
});

// Przelewy24 webhook (no auth, no CSRF — excluded in bootstrap/app.php)
Route::post('/webhooks/przelewy24', [WebhookController::class, 'przelewy24'])
    ->middleware([ResolveTenant::class, 'throttle:60,1'])
    ->name('webhooks.p24');

// Authentication routes (register disabled here, handled manually below with middleware)
// Wrapped in ResolveTenant so LoginController knows which tenant subdomain the user is on

// GET /login — no throttle (just rendering the form, no brute-force risk)
Route::get('/login', [\App\Http\Controllers\Auth\LoginController::class, 'showLoginForm'])
    ->middleware([ResolveTenant::class, 'guest'])
    ->name('login');

// POST /login + other auth actions — brute-force protection (5/min per IP)
Route::middleware([ResolveTenant::class, 'throttle:5,1'])->group(function () {
    Auth::routes(['login' => false, 'register' => false]);
    Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'login']);
});

// Business registration (root domain: /register → 2-step self-serve wizard).
// Registered ONLY on the shared legacy stack (TENANT_SLUG unset). A dedicated
// tenant-stack container already gets its one organization from
// `registro:tenant-provision` at boot, and organizations.singleton (see its
// migration) would reject a 2nd one at the DB level regardless — but gating at
// route-registration time means the endpoint does not exist here at all: no
// middleware to forget, nothing to bypass. Visible by reading this file, unlike
// a middleware attached elsewhere.
if (! config('app.tenant_slug')) {
    Route::middleware(['guest'])->group(function () {
        Route::get('/register', [BusinessRegisterController::class, 'showStep1'])
            ->name('register');
        Route::post('/register/step/1', [BusinessRegisterController::class, 'storeStep1'])
            ->middleware('throttle:10,1')
            ->name('register.step1.store');
        Route::get('/register/step/2', [BusinessRegisterController::class, 'showStep2'])
            ->name('register.step2');
        Route::post('/register/step/2', [BusinessRegisterController::class, 'storeStep2'])
            ->middleware('throttle:5,1')
            ->name('register.step2.store');
    });

    // Business registration step 3 + welcome (auth required)
    Route::middleware(['auth'])->group(function () {
        Route::get('/register/step/3', [BusinessRegisterController::class, 'showStep3'])
            ->name('register.step3');
        Route::post('/register/step/3', [BusinessRegisterController::class, 'storeStep3'])
            ->middleware('throttle:10,1')
            ->name('register.step3.store');
        Route::get('/register/welcome', [BusinessRegisterController::class, 'welcome'])
            ->name('register.welcome');
    });

    // Business registration AJAX (throttled)
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/register/check-slug', [BusinessRegisterController::class, 'checkSlug'])
            ->name('register.check-slug');
        Route::get('/register/generate-slug', [BusinessRegisterController::class, 'generateSlug'])
            ->name('register.generate-slug');
    });

    // Backwards compatibility: /get-started → /register
    Route::redirect('/get-started', '/register', 301);
    Route::redirect('/get-started/step/2', '/register/step/2', 301);
    Route::redirect('/get-started/welcome', '/register/welcome', 301);
}

// Customer registration (tenant subdomain: /register → single-step)
// ResolveTenant needed to attach user to organization on subdomain registration
Route::get('/customer/register', [RegisterController::class, 'showRegistrationForm'])
    ->middleware(['guest', ResolveTenant::class, CheckRegistrationEnabled::class])
    ->name('customer.register');
Route::post('/customer/register', [RegisterController::class, 'register'])
    ->middleware(['guest', ResolveTenant::class, CheckRegistrationEnabled::class]);

// Password Setup Routes (for admin-created users)
Route::get('/password/setup/{token}', [App\Http\Controllers\Auth\SetPasswordController::class, 'show'])
    ->name('password.setup');
Route::post('/password/setup', [App\Http\Controllers\Auth\SetPasswordController::class, 'store'])
    ->name('password.setup.store')
    ->middleware('throttle:6,1'); // Rate limit: 6 attempts per minute

// Webhook routes (no authentication required, rate limited)
Route::prefix('api/webhooks')->name('webhooks.')->middleware('throttle:120,1')->group(function () {
    Route::post('/smsapi/delivery-status', [SmsApiWebhookController::class, 'handleDeliveryStatus'])
        ->name('smsapi.delivery-status');

    Route::post('/smsapi/incoming', [SmsApiIncomingController::class, 'handleIncoming'])
        ->name('smsapi.incoming');
});

// Protected routes (require authentication + tenant resolution)
// RequireTenant closes the VULN-003 session-fallback gap: booking/appointments/profile
// routes must hard-404 on the root domain even when a stale session tenant_id exists —
// see app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md (Layer 3).
Route::middleware(['auth', ResolveTenant::class, RequireTenant::class])->group(function () {
    // Booking routes - protected by CheckBookingEnabled middleware
    // When booking is disabled, these redirect to home page
    Route::middleware([CheckBookingEnabled::class])->group(function () {
        // Booking Wizard (new multi-step flow) + old single-page flow's view route —
        // VULN-001: view/AJAX-restore GET endpoints rate limited (60/min per user/IP)
        // to close the gap left by the original fix (only the POST endpoints were throttled).
        Route::middleware(['throttle:60,1'])->group(function () {
            Route::get('/services/{service}/book', [BookingController::class, 'create'])->name('booking.create');
            Route::get('/booking/step/{step}', [BookingController::class, 'showStep'])->name('booking.step');
            Route::get('/booking/change-service', [BookingController::class, 'changeService'])->name('booking.change-service');
            Route::get('/booking/restore-progress', [BookingController::class, 'restoreProgress'])->name('booking.restore-progress');
        });

        // Booking Wizard - Rate Limited POST endpoints
        Route::middleware(['throttle:30,1'])->group(function () {
            Route::post('/booking/step/{step}', [BookingController::class, 'storeStep'])->name('booking.step.store');
            Route::post('/booking/save-progress', [BookingController::class, 'saveProgress'])->name('booking.save-progress');
        });

        // Heavy-computation availability endpoints (AJAX) — VULN-001: stricter limit (20/min).
        // unavailable-dates scans 60 days across all staff for a service; available-slots
        // (old single-page flow) computes per-staff slot availability for a single day and
        // has the same-or-worse DoS profile (AppointmentService::getAvailableSlotsAcrossAllStaff()),
        // so it gets the same tier for consistency.
        Route::middleware(['throttle:20,1'])->group(function () {
            Route::get('/booking/unavailable-dates', [BookingController::class, 'getUnavailableDates'])
                ->name('booking.unavailable-dates');
            Route::get('/booking/available-slots', [BookingController::class, 'getAvailableSlots'])->name('booking.slots');
        });

        // Booking confirmation - Stricter rate limit (production only)
        $confirmThrottle = app()->environment('production') ? 'throttle:10,1' : 'throttle:100,1';
        Route::middleware([$confirmThrottle])->group(function () {
            Route::post('/booking/confirm', [BookingController::class, 'confirm'])->name('booking.confirm');
        });
    });

    // These booking routes stay accessible even when booking is disabled
    // (user may have just completed a booking before toggle was turned off)
    Route::get('/booking/confirmation', [BookingController::class, 'showConfirmation'])->name('booking.confirmation');
    Route::get('/booking/ical/{appointment}', [BookingController::class, 'downloadIcal'])->name('booking.ical');

    // Appointments
    Route::get('/my-appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');

    // Profile routes
    Route::prefix('moje-konto')->name('profile.')->group(function () {
        // Profile index with grouped list navigation (iOS pattern)
        Route::get('/', [ProfileController::class, 'index'])->name('index');

        // Profile pages
        Route::get('/dane-osobowe', [ProfileController::class, 'personal'])->name('personal');
        Route::get('/pojazd', [ProfileController::class, 'vehicle'])->name('vehicle');
        Route::get('/adres', [ProfileController::class, 'address'])->name('address');
        Route::get('/powiadomienia', [ProfileController::class, 'notifications'])->name('notifications');
        Route::get('/bezpieczenstwo', [ProfileController::class, 'security'])->name('security');

        // Personal Info update
        Route::patch('/dane-osobowe', [ProfileController::class, 'updatePersonalInfo'])->name('personal.update');

        // Email Change
        Route::post('/email/zmien', [ProfileController::class, 'requestEmailChange'])->name('email.change');
        Route::get('/email/potwierdz/{token}', [ProfileController::class, 'confirmEmailChange'])->name('email.confirm');

        // Password
        Route::patch('/haslo', [ProfileController::class, 'changePassword'])->name('password.update');

        // Notifications update
        Route::patch('/powiadomienia/zapisz', [ProfileController::class, 'updateNotifications'])->name('notifications.update');

        // Vehicle (single)
        Route::post('/pojazd/zapisz', [UserVehicleController::class, 'store'])->name('vehicle.store');
        Route::patch('/pojazd/{vehicle}', [UserVehicleController::class, 'update'])->name('vehicle.update');
        Route::delete('/pojazd/{vehicle}', [UserVehicleController::class, 'destroy'])->name('vehicle.destroy');

        // Address (single)
        Route::post('/adres/zapisz', [UserAddressController::class, 'store'])->name('address.store');
        Route::patch('/adres/{address}', [UserAddressController::class, 'update'])->name('address.update');
        Route::delete('/adres/{address}', [UserAddressController::class, 'destroy'])->name('address.destroy');

        // Account Deletion
        Route::post('/usun-konto', [ProfileController::class, 'requestDeletion'])->name('delete.request');
        Route::get('/usun-konto/potwierdz/{token}', [ProfileController::class, 'confirmDeletion'])->name('delete.confirm');
        Route::post('/usun-konto/anuluj', [ProfileController::class, 'cancelDeletion'])->name('delete.cancel');

        // GDPR Data Export (Art. 20 - Right to data portability)
        Route::get('/eksport-danych', [ProfileController::class, 'exportData'])
            ->name('data.export')
            ->middleware('throttle:1,1440'); // 1 request per 24 hours
    });
});

Route::prefix('api')->name('api.')->middleware(['auth', ResolveTenant::class, 'throttle:60,1,vehicle-data'])->group(function () {
    Route::get('/vehicle-types', [VehicleDataController::class, 'vehicleTypes'])->name('vehicle-types');
    Route::get('/car-brands', [VehicleDataController::class, 'brands'])->name('car-brands');
    Route::get('/car-models', [VehicleDataController::class, 'models'])->name('car-models');
    Route::get('/vehicle-years', [VehicleDataController::class, 'years'])->name('vehicle-years');
});

// =============================================================================
// DEV ONLY — fake payment bypass (non-production environments)
// =============================================================================
if (! app()->isProduction()) {
    Route::post('/dev/fake-pay', [FakePaymentController::class, 'pay'])
        ->middleware(['auth', ResolveTenant::class, RequireTenant::class])
        ->name('dev.fake-pay');
}

// =============================================================================
// Platform — Organization Data Export (signed URL, Art. 28(3)(g) RODO)
// =============================================================================
// Accessible by: valid signed URL (7 days) OR authenticated super-admin.
// Authorization is handled in the controller — no auth middleware here,
// because the org owner may not have a Registro account / be logged in.
// Throttled: the signed URL streams a ZIP with full org PII.
//
// Gated on TENANT_SLUG like the rest of /platform: this is the one platform
// route defined here rather than by PlatformPanelProvider, so skipping that
// provider does not remove it. It carries no auth middleware by design, and a
// dedicated tenant stack has no super-admin and its own APP_KEY (so a signed URL
// minted elsewhere will not validate) — but an unauthenticated endpoint that
// streams full-PII exports has no reason to exist in a client's container.
// =============================================================================
if (! config('app.tenant_slug')) {
    Route::get('/platform/organizations/{organization}/data-export', [\App\Http\Controllers\Platform\OrganizationDataExportController::class, 'download'])
        ->name('platform.organization.data-export')
        ->middleware('throttle:10,1440');
}

// =============================================================================
// CMS Pages - Catch-all Route (MUST BE LAST!)
// =============================================================================
// Pages are served at root level: /{slug} (WordPress-style SEO-friendly URLs)
// Example: /o-nas, /kontakt, /regulamin-promocji
//
// This route MUST be defined LAST to prevent matching other routes.
// Reserved slugs are blocked in Page model validation.
// =============================================================================
Route::middleware([ResolveTenant::class, RequireTenant::class])->get('/{slug}', [PageController::class, 'show'])
    ->name('page.show')
    ->where('slug', '^(?!admin|platform|api|livewire|filament|horizon|storage|sanctum|health|register|customer|get-started).*$');
