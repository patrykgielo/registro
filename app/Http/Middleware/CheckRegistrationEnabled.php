<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Settings\SettingsManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(SettingsManager::class)->isRegistrationEnabled()) {
            return redirect()->route('login')
                ->with('info', 'Rejestracja jest tymczasowo niedostępna.');
        }

        return $next($request);
    }
}
