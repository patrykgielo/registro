<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Settings\SettingsManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBookingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(SettingsManager::class)->isBookingEnabled()) {
            return redirect()->route('home')
                ->with('info', 'Rezerwacja online jest tymczasowo niedostępna. Skontaktuj się z nami telefonicznie.');
        }

        return $next($request);
    }
}
