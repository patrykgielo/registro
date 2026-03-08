<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreVehicleRequest;
use App\Http\Requests\Profile\UpdateVehicleRequest;
use App\Models\UserVehicle;
use App\Services\UserVehicleService;
use App\Support\TenantFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserVehicleController extends Controller
{
    public function __construct(
        protected UserVehicleService $vehicleService
    ) {}

    /**
     * Store a new vehicle.
     */
    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        if (! TenantFeature::active('vehicles')) {
            abort(404);
        }

        $this->vehicleService->create(
            $request->user(),
            $request->validated()
        );

        return redirect()->route('profile.vehicle')
            ->with('success', __('Pojazd został dodany.'));
    }

    /**
     * Update existing vehicle.
     */
    public function update(UpdateVehicleRequest $request, UserVehicle $vehicle): RedirectResponse
    {
        if (! TenantFeature::active('vehicles')) {
            abort(404);
        }

        // Ensure vehicle belongs to user
        if ($vehicle->user_id !== $request->user()->id) {
            abort(403);
        }

        $this->vehicleService->update($vehicle, $request->validated());

        return redirect()->route('profile.vehicle')
            ->with('success', __('Pojazd został zaktualizowany.'));
    }

    /**
     * Delete vehicle.
     */
    public function destroy(Request $request, UserVehicle $vehicle): RedirectResponse
    {
        if (! TenantFeature::active('vehicles')) {
            abort(404);
        }

        // Ensure vehicle belongs to user
        if ($vehicle->user_id !== $request->user()->id) {
            abort(403);
        }

        $this->vehicleService->delete($vehicle);

        return redirect()->route('profile.vehicle')
            ->with('success', __('Pojazd został usunięty.'));
    }
}
