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
