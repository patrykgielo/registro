<?php

use App\Http\Controllers\Api\SmsApiIncomingController;
use App\Http\Controllers\Api\SmsApiWebhookController;
use App\Http\Controllers\Api\VehicleDataController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\BusinessRegisterController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\UserAddressController;
use App\Http\Controllers\UserVehicleController;
use App\Http\Middleware\CheckBookingEnabled;
use App\Http\Middleware\CheckRegistrationEnabled;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Public routes
Route::middleware('web')->get('/', function () {
    $settingsManager = app(\App\Support\Settings\SettingsManager::class);
    $pageId = $settingsManager->get('cms.homepage_page_id');

    if (! $pageId) {
        return view('home-fallback');
    }

    $page = \App\Models\Page::find($pageId);

    if (! $page || ! $page->isPublished()) {
        abort(404, 'Homepage not found or not published');
    }

    return view('pages.show', [
        'page' => $page,
        'layout' => $page->layout,
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
Route::get('/aktualnosci/{slug}', [PostController::class, 'show'])->name('post.show');
Route::get('/promocje/{slug}', [PromotionController::class, 'show'])->name('promotion.show');
Route::get('/portfolio/{slug}', [PortfolioController::class, 'show'])->name('portfolio.show');

// Legacy redirect: /strona/{slug} -> /{slug} (SEO 301 permanent redirect)
Route::get('/strona/{slug}', function (string $slug) {
    return redirect()->route('page.show', $slug, 301);
})->name('page.legacy');

// Service Pages routes (P0: SEO-friendly Polish URLs with rate limiting)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/uslugi', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/uslugi/{service:slug}', [ServiceController::class, 'show'])->name('service.show');
});

// Authentication routes (register disabled here, handled manually below with middleware)
// Wrapped in ResolveTenant so LoginController knows which tenant subdomain the user is on
// Rate limited: 5 login attempts per minute per IP (brute-force protection)
Route::middleware([ResolveTenant::class, 'throttle:5,1'])->group(function () {
    Auth::routes(['register' => false]);
});

// Business registration (root domain: /register → 2-step wizard)
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

// Customer registration (tenant subdomain: /register → single-step)
// ResolveTenant needed to attach user to organization on subdomain registration
Route::get('/customer/register', [RegisterController::class, 'showRegistrationForm'])
    ->middleware(['guest', ResolveTenant::class, CheckRegistrationEnabled::class])
    ->name('customer.register');
Route::post('/customer/register', [RegisterController::class, 'register'])
    ->middleware(['guest', ResolveTenant::class, CheckRegistrationEnabled::class]);

// Backwards compatibility: /get-started → /register
Route::redirect('/get-started', '/register', 301);
Route::redirect('/get-started/step/2', '/register/step/2', 301);
Route::redirect('/get-started/welcome', '/register/welcome', 301);

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
Route::middleware(['auth', ResolveTenant::class])->group(function () {
    // Booking routes - protected by CheckBookingEnabled middleware
    // When booking is disabled, these redirect to home page
    Route::middleware([CheckBookingEnabled::class])->group(function () {
        // Booking (old single-page flow)
        Route::get('/services/{service}/book', [BookingController::class, 'create'])->name('booking.create');
        Route::get('/booking/available-slots', [BookingController::class, 'getAvailableSlots'])->name('booking.slots');

        // Booking Wizard (new multi-step flow)
        Route::get('/booking/step/{step}', [BookingController::class, 'showStep'])->name('booking.step');
        Route::get('/booking/change-service', [BookingController::class, 'changeService'])->name('booking.change-service');

        // Booking Wizard - Rate Limited POST endpoints
        Route::middleware(['throttle:30,1'])->group(function () {
            Route::post('/booking/step/{step}', [BookingController::class, 'storeStep'])->name('booking.step.store');
            Route::post('/booking/save-progress', [BookingController::class, 'saveProgress'])->name('booking.save-progress');
        });

        Route::get('/booking/restore-progress', [BookingController::class, 'restoreProgress'])->name('booking.restore-progress');

        // Calendar availability endpoint (AJAX)
        Route::get('/booking/unavailable-dates', [BookingController::class, 'getUnavailableDates'])
            ->name('booking.unavailable-dates');

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

Route::prefix('api')->name('api.')->middleware(['auth', ResolveTenant::class])->group(function () {
    Route::get('/vehicle-types', [VehicleDataController::class, 'vehicleTypes'])->name('vehicle-types');
    Route::get('/car-brands', [VehicleDataController::class, 'brands'])->name('car-brands');
    Route::get('/car-models', [VehicleDataController::class, 'models'])->name('car-models');
    Route::get('/vehicle-years', [VehicleDataController::class, 'years'])->name('vehicle-years');
});

// =============================================================================
// CMS Pages - Catch-all Route (MUST BE LAST!)
// =============================================================================
// Pages are served at root level: /{slug} (WordPress-style SEO-friendly URLs)
// Example: /o-nas, /kontakt, /regulamin-promocji
//
// This route MUST be defined LAST to prevent matching other routes.
// Reserved slugs are blocked in Page model validation.
// =============================================================================
Route::get('/{slug}', [PageController::class, 'show'])
    ->name('page.show')
    ->where('slug', '^(?!admin|platform|api|livewire|filament|horizon|storage|sanctum|health|register|customer|get-started).*$');
