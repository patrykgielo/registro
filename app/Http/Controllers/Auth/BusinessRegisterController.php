<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Onboarding\CreateOrganizationWithOwner;
use App\Actions\Onboarding\GenerateUniqueSlug;
use App\Actions\Onboarding\OnboardingData;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Rules\ValidOrganizationSlug;
use App\Support\TenantUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BusinessRegisterController extends Controller
{
    public function showStep1(Request $request): View|RedirectResponse
    {
        // On tenant subdomains, redirect to customer registration
        if ($request->attributes->get('tenant')) {
            return redirect()->route('register');
        }

        return view('auth.register-business-step1', [
            'data' => $request->session()->get('business_register.step1', []),
        ]);
    }

    public function storeStep1(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'org_name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', new ValidOrganizationSlug, 'unique:organizations,slug'],
            'booking_type' => ['required', 'in:time_slot,item_rental,both'],
        ]);

        $request->session()->put('business_register.step1', $validated);

        return redirect()->route('register.step2');
    }

    public function showStep2(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('business_register.step1')) {
            return redirect()->route('register');
        }

        return view('auth.register-business-step2', [
            'step1' => $request->session()->get('business_register.step1'),
        ]);
    }

    public function storeStep2(
        Request $request,
        CreateOrganizationWithOwner $createAction,
    ): RedirectResponse {
        $step1 = $request->session()->get('business_register.step1');

        if (! $step1) {
            return redirect()->route('register');
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'terms' => ['required', 'accepted'],
        ]);

        // Re-validate slug uniqueness (race condition guard)
        if (Organization::withoutGlobalScopes()->where('slug', $step1['slug'])->exists()) {
            $slugGenerator = app(GenerateUniqueSlug::class);
            $step1['slug'] = $slugGenerator->execute($step1['org_name']);
        }

        $data = new OnboardingData(
            orgName: $step1['org_name'],
            slug: $step1['slug'],
            bookingType: $step1['booking_type'],
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            email: $validated['email'],
            password: $validated['password'],
        );

        $result = $createAction->execute($data);

        Auth::login($result['user']);

        $request->session()->forget('business_register');
        $request->session()->put('business_register.organization_id', $result['organization']->id);

        return redirect()->route('register.welcome');
    }

    public function welcome(Request $request): View|RedirectResponse
    {
        $orgId = $request->session()->get('business_register.organization_id');

        if (! $orgId) {
            return redirect()->route('register');
        }

        $org = Organization::findOrFail($orgId);

        return view('onboarding.welcome', [
            'organization' => $org,
            'adminUrl' => TenantUrl::admin($org),
        ]);
    }

    /**
     * AJAX endpoint for real-time slug availability checking.
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $slug = strtolower(trim($request->query('slug', '')));

        if (strlen($slug) < 3) {
            return response()->json(['available' => false, 'suggestion' => null]);
        }

        $reserved = ValidOrganizationSlug::reservedSlugs();
        $taken = in_array($slug, $reserved, true)
            || Organization::withoutGlobalScopes()->where('slug', $slug)->exists();

        $suggestion = null;
        if ($taken) {
            $slugGenerator = app(GenerateUniqueSlug::class);

            try {
                $suggestion = $slugGenerator->execute($slug);
            } catch (\RuntimeException) {
                // All variants taken
            }
        }

        return response()->json([
            'available' => ! $taken,
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * Auto-generate slug from org name (AJAX).
     */
    public function generateSlug(Request $request): JsonResponse
    {
        $name = $request->query('name', '');

        if (strlen($name) < 2) {
            return response()->json(['slug' => '']);
        }

        $slugGenerator = app(GenerateUniqueSlug::class);

        return response()->json([
            'slug' => $slugGenerator->execute($name),
        ]);
    }
}
