<?php

namespace App\Http\Controllers\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\CustomerLandingUrl;
use App\Support\Auth\IntendedDestination;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    use RegistersUsers {
        register as registerUser;
    }

    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Show the customer registration form.
     *
     * Captures where the visitor came from (see IntendedDestination) before
     * the tenant check below — covers card → /login → "Nie mam konta" →
     * /customer/register, where previousRoute() here is 'login' itself and
     * IntendedDestination::capture() keeps the value login already captured
     * rather than overwriting it with the auth page.
     *
     * On root domain (no tenant resolved) there is nothing to register a
     * customer against -- the public business-registration wizard this used
     * to fall back to is gone (see routes/web.php), and customer accounts
     * only ever belong to a tenant. Send the visitor to login instead of a
     * dead route.
     */
    public function showRegistrationForm(Request $request)
    {
        IntendedDestination::capture($request);

        return $this->redirectWithoutTenant($request) ?? view('auth.register');
    }

    /**
     * Handle a registration request.
     *
     * Same tenant guard as the form (redirectWithoutTenant()), and it runs FIRST --
     * before validation and before any write. ResolveTenant deliberately lets the
     * root domain through without a tenant, and CheckRegistrationEnabled treats
     * "no tenant" as enabled, so without this a POST to the root domain created a
     * customer attached to no organization: an orphan that holds its (unique)
     * e-mail and blocks the same person's later registration on a real tenant.
     * Running before validation also keeps the root domain from answering
     * "e-mail already taken" to anonymous probes.
     */
    public function register(Request $request)
    {
        return $this->redirectWithoutTenant($request) ?? $this->registerUser($request);
    }

    /**
     * The single "no tenant, no customer registration" decision, shared by the form
     * (GET) and the submit (POST) so the two cannot drift apart again.
     *
     * Reads the `tenant` request ATTRIBUTE (set by ResolveTenant for THIS request,
     * on both the shared stack and the pinned dedicated stack) -- never
     * TenantFeature::currentTenant(), whose session fallback is not a per-request
     * signal (see .claude/rules/middleware.md, RequireTenant).
     *
     * Redirect rather than 404, deliberately: it is what the form has always
     * answered, an existing test pins it, and a root-domain visitor who somehow
     * submits is better sent to the login page than shown an error.
     */
    private function redirectWithoutTenant(Request $request): ?RedirectResponse
    {
        if ($request->attributes->get('tenant') instanceof Organization) {
            return null;
        }

        return redirect()->route('login');
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
     * dispatches UserRegistered event for welcome email, and returns the
     * captured IntendedDestination (or CustomerLandingUrl as a fallback) —
     * a non-null return here short-circuits RegistersUsers::register()'s own
     * `redirect($this->redirectPath())` fallback.
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

        event(new UserRegistered($user, $tenant instanceof Organization ? $tenant : null));

        return redirect(IntendedDestination::consume($request) ?? CustomerLandingUrl::for($request));
    }
}
