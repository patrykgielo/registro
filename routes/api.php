<?php

use App\Http\Controllers\Api\EventTrackingController;
use App\Http\Controllers\Api\ServiceAreaController;
use App\Http\Controllers\ServiceAreaWaitlistController;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rate limits: Local/staging = relaxed, Production = strict
$isProduction = app()->environment('production');

// Service Area Validation (throttled)
// ResolveTenant + RequireTenant: these are same-origin fetch() calls from pages
// already loaded on the tenant subdomain (serviceAreaMap.js uses relative URLs),
// so Host-header-based resolution works transparently. Without this, the query
// was completely unscoped — see VULN-003 gap #2.
Route::middleware([ResolveTenant::class, RequireTenant::class, $isProduction ? 'throttle:10,1' : 'throttle:100,1'])->group(function () {
    Route::post('/service-area/validate', [ServiceAreaController::class, 'validateLocation'])
        ->name('api.service-area.validate');
});

// Service Areas for Map Display (throttled)
Route::middleware([ResolveTenant::class, RequireTenant::class, $isProduction ? 'throttle:30,1' : 'throttle:300,1'])->group(function () {
    Route::get('/service-area/areas', [ServiceAreaController::class, 'getServiceAreas'])
        ->name('api.service-area.areas');
});

// Waitlist Submission (strict rate limiting)
// NOTE: ServiceAreaWaitlist has no organization_id / BelongsToOrganization at all —
// a separate pre-existing design question, not this vulnerability class. Left
// unscoped intentionally; see VULN-003 doc "Follow-ups".
Route::middleware($isProduction ? 'throttle:3,1' : 'throttle:30,1')->group(function () {
    Route::post('/service-area/waitlist', [ServiceAreaWaitlistController::class, 'store'])
        ->name('api.service-area.waitlist');
});

// Frontend event tracking (guest + authenticated, tenant-scoped)
// ResolveTenant must run BEFORE throttle:analytics so the per-tenant bucket key is available.
Route::middleware([\App\Http\Middleware\ResolveTenant::class, 'throttle:analytics'])->group(function () {
    Route::post('/track', [EventTrackingController::class, 'store'])
        ->name('api.analytics.track');
});
