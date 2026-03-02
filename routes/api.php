<?php

use App\Http\Controllers\Api\ServiceAreaController;
use App\Http\Controllers\ServiceAreaWaitlistController;
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
Route::middleware($isProduction ? 'throttle:10,1' : 'throttle:100,1')->group(function () {
    Route::post('/service-area/validate', [ServiceAreaController::class, 'validateLocation'])
        ->name('api.service-area.validate');
});

// Service Areas for Map Display (throttled)
Route::middleware($isProduction ? 'throttle:30,1' : 'throttle:300,1')->group(function () {
    Route::get('/service-area/areas', [ServiceAreaController::class, 'getServiceAreas'])
        ->name('api.service-area.areas');
});

// Waitlist Submission (strict rate limiting)
Route::middleware($isProduction ? 'throttle:3,1' : 'throttle:30,1')->group(function () {
    Route::post('/service-area/waitlist', [ServiceAreaWaitlistController::class, 'store'])
        ->name('api.service-area.waitlist');
});
