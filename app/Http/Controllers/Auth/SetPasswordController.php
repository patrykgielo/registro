<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class SetPasswordController extends Controller
{
    public function show(string $token)
    {
        \Log::info('[PASSWORD_SETUP] Setup page accessed', [
            'token_preview' => substr($token, 0, 8).'...',
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $user = User::where('password_setup_token', $token)->first();

        if (! $user) {
            \Log::warning('[PASSWORD_SETUP] Token not found in database', [
                'token_preview' => substr($token, 0, 8).'...',
            ]);

            return view('auth.passwords.token-expired');
        }

        if ($user->password_setup_expires_at?->isPast()) {
            \Log::warning('[PASSWORD_SETUP] Token expired', [
                'user_id' => $user->id,
                'email' => $user->email,
                'expired_at' => $user->password_setup_expires_at->toIso8601String(),
                'now' => now()->toIso8601String(),
            ]);

            return view('auth.passwords.token-expired');
        }

        \Log::info('[PASSWORD_SETUP] Token valid, showing setup form', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return view('auth.passwords.setup', ['token' => $token, 'email' => $user->email]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::where('password_setup_token', $request->token)->first();

        if (! $user || $user->password_setup_expires_at?->isPast()) {
            return back()->withErrors(['token' => 'Link wygasł lub jest nieprawidłowy.']);
        }

        $user->completePasswordSetup($request->token, $request->password);

        return redirect()->route('login')
            ->with('status', 'Hasło ustawione pomyślnie. Możesz się teraz zalogować.');
    }
}
