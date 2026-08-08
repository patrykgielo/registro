<?php

namespace App\Http\Controllers\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     */
    protected $redirectTo = '/';

    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Show the customer registration form.
     *
     * On root domain (no tenant resolved) there is nothing to register a
     * customer against -- the public business-registration wizard this used
     * to fall back to is gone (see routes/web.php), and customer accounts
     * only ever belong to a tenant. Send the visitor to login instead of a
     * dead route.
     */
    public function showRegistrationForm(Request $request)
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant) {
            return redirect()->route('login');
        }

        return view('auth.register');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        return User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    /**
     * The user has been registered.
     *
     * Assigns 'customer' role, attaches to tenant organization (if on subdomain),
     * and dispatches UserRegistered event for welcome email.
     *
     * @return void
     */
    protected function registered(Request $request, User $user)
    {
        // Rotate the session ID post-authentication (session-fixation defense-in-depth),
        // matching the flow already enforced by LoginController::authenticated() via
        // AuthenticatesUsers::sendLoginResponse().
        $request->session()->regenerate();

        $user->assignRole('customer');

        // Attach user to tenant organization when registering on a subdomain
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof Organization) {
            $user->organizations()->syncWithoutDetaching([
                $tenant->id => ['role' => 'customer'],
            ]);
        }

        event(new UserRegistered($user));
    }
}
