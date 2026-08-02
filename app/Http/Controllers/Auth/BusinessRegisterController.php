<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Onboarding\CreateOrganizationWithOwner;
use App\Actions\Onboarding\GenerateUniqueSlug;
use App\Actions\Onboarding\OnboardingData;
use App\Enums\Industry;
use App\Events\TenantRegistered;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Rules\ValidOrganizationSlug;
use App\Support\TenantUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
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
            'industries' => Industry::cases(),
        ]);
    }

    public function storeStep1(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'org_name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', new ValidOrganizationSlug, 'unique:organizations,slug'],
            'industry' => ['required', new Enum(Industry::class)],
        ]);

        $request->session()->put('business_register.step1', $validated);

        return redirect()->route('register.step2');
    }

    public function showStep2(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('business_register.step1')) {
            return redirect()->route('register');
        }

        $step1 = $request->session()->get('business_register.step1');
        $industry = Industry::from($step1['industry']);

        return view('auth.register-business-step2', [
            'step1' => $step1,
            'industry' => $industry,
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

        $industry = Industry::from($step1['industry']);

        $data = new OnboardingData(
            orgName: $step1['org_name'],
            slug: $step1['slug'],
            bookingType: $industry->bookingType(),
            industry: $step1['industry'],
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            email: $validated['email'],
            password: $validated['password'],
        );

        $result = $createAction->execute($data);

        // Fires the mails that this flow never sent: a welcome to the owner --
        // who otherwise leaves with no record of their panel address -- and a
        // heads-up to the operator, who otherwise learns about new tenants only
        // by looking. Dispatched AFTER the organisation and owner both exist so
        // the queued notifications cannot race their own subjects.
        TenantRegistered::dispatch($result['organization'], $result['user']);

        Auth::login($result['user']);
        $request->session()->regenerate();

        $request->session()->forget('business_register');
        $request->session()->put('business_register.organization_id', $result['organization']->id);

        return redirect()->route('register.step3');
    }

    public function showStep3(Request $request): View|RedirectResponse
    {
        $orgId = $request->session()->get('business_register.organization_id');

        if (! $orgId) {
            return redirect()->route('register');
        }

        $org = Organization::findOrFail($orgId);

        return view('onboarding.step3', [
            'organization' => $org,
            'industry' => $org->industry,
            'adminUrl' => TenantUrl::admin($org),
        ]);
    }

    public function storeStep3(Request $request): RedirectResponse
    {
        $orgId = $request->session()->get('business_register.organization_id');

        if (! $orgId) {
            return redirect()->route('register');
        }

        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'mobile_service' => ['nullable', 'boolean'],
            'service_radius_km' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $settings = $org->settings ?? [];

        if (! empty($validated['city'])) {
            data_set($settings, 'location.city', $validated['city']);
        }

        if (! empty($validated['address'])) {
            data_set($settings, 'location.address', $validated['address']);
        }

        if (isset($validated['mobile_service'])) {
            data_set($settings, 'features.mobile_service', (bool) $validated['mobile_service']);
        }

        if (! empty($validated['service_radius_km'])) {
            data_set($settings, 'location.service_radius_km', (int) $validated['service_radius_km']);
        }

        $org->update(['settings' => $settings]);

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
