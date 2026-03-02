<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserVehicle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class UserVehicleService
{
    /**
     * Create a new vehicle for user.
     *
     * @throws ValidationException If user reached vehicle limit
     */
    public function create(User $user, array $data): UserVehicle
    {
        if (! $user->canAddVehicle()) {
            throw ValidationException::withMessages([
                'vehicle' => [__('Osiągnięto limit pojazdów. Skontaktuj się z administratorem, aby zwiększyć limit.')],
            ]);
        }

        // If this is the first vehicle, make it default
        $isFirst = $user->vehicles()->count() === 0;

        // If setting as default, clear other defaults
        if ($data['is_default'] ?? $isFirst) {
            $user->vehicles()->update(['is_default' => false]);
        }

        return $user->vehicles()->create([
            'vehicle_type_id' => $data['vehicle_type_id'],
            'car_brand_id' => $data['car_brand_id'] ?? null,
            'car_model_id' => $data['car_model_id'] ?? null,
            'custom_brand' => $data['custom_brand'] ?? null,
            'custom_model' => $data['custom_model'] ?? null,
            'year' => $data['year'] ?? null,
            'nickname' => $data['nickname'] ?? null,
            'is_default' => $data['is_default'] ?? $isFirst,
        ]);
    }

    /**
     * Update existing vehicle.
     *
     * @throws ModelNotFoundException If vehicle doesn't exist
     */
    public function update(UserVehicle $vehicle, array $data): UserVehicle
    {
        // If setting as default, clear other defaults first
        if ($data['is_default'] ?? false) {
            $vehicle->user->vehicles()
                ->where('id', '!=', $vehicle->id)
                ->update(['is_default' => false]);
        }

        $vehicle->update([
            'vehicle_type_id' => $data['vehicle_type_id'] ?? $vehicle->vehicle_type_id,
            'car_brand_id' => $data['car_brand_id'] ?? $vehicle->car_brand_id,
            'car_model_id' => $data['car_model_id'] ?? $vehicle->car_model_id,
            'custom_brand' => $data['custom_brand'] ?? $vehicle->custom_brand,
            'custom_model' => $data['custom_model'] ?? $vehicle->custom_model,
            'year' => $data['year'] ?? $vehicle->year,
            'nickname' => $data['nickname'] ?? $vehicle->nickname,
            'is_default' => $data['is_default'] ?? $vehicle->is_default,
        ]);

        return $vehicle->fresh();
    }

    /**
     * Delete vehicle.
     * If deleting default vehicle, make another one default (if exists).
     */
    public function delete(UserVehicle $vehicle): bool
    {
        $user = $vehicle->user;
        $wasDefault = $vehicle->is_default;

        $vehicle->delete();

        // If deleted vehicle was default, make another one default
        if ($wasDefault) {
            $newDefault = $user->vehicles()->first();
            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return true;
    }

    /**
     * Get vehicle for user, ensuring it belongs to them.
     *
     * @throws ModelNotFoundException If not found or doesn't belong to user
     */
    public function getForUser(User $user, int $vehicleId): UserVehicle
    {
        $vehicle = UserVehicle::where('user_id', $user->id)
            ->where('id', $vehicleId)
            ->first();

        if (! $vehicle) {
            throw new ModelNotFoundException('Vehicle not found.');
        }

        return $vehicle;
    }
}
