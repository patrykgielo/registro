<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreAddressRequest;
use App\Http\Requests\Profile\UpdateAddressRequest;
use App\Models\UserAddress;
use App\Services\UserAddressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserAddressController extends Controller
{
    public function __construct(
        protected UserAddressService $addressService
    ) {}

    /**
     * Store a new address.
     */
    public function store(StoreAddressRequest $request): RedirectResponse
    {
        $this->addressService->create(
            $request->user(),
            $request->validated()
        );

        return redirect()->route('profile.address')
            ->with('success', __('Adres został dodany.'));
    }

    /**
     * Update existing address.
     */
    public function update(UpdateAddressRequest $request, UserAddress $address): RedirectResponse
    {
        // Ensure address belongs to user
        if ($address->user_id !== $request->user()->id) {
            abort(403);
        }

        $this->addressService->update($address, $request->validated());

        return redirect()->route('profile.address')
            ->with('success', __('Adres został zaktualizowany.'));
    }

    /**
     * Delete address.
     */
    public function destroy(Request $request, UserAddress $address): RedirectResponse
    {
        // Ensure address belongs to user
        if ($address->user_id !== $request->user()->id) {
            abort(403);
        }

        $this->addressService->delete($address);

        return redirect()->route('profile.address')
            ->with('success', __('Adres został usunięty.'));
    }
}
