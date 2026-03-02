<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class UserAddressService
{
    /**
     * Create a new address for user.
     *
     * @throws ValidationException If user reached address limit
     */
    public function create(User $user, array $data): UserAddress
    {
        if (! $user->canAddAddress()) {
            throw ValidationException::withMessages([
                'address' => [__('Osiągnięto limit adresów. Skontaktuj się z administratorem, aby zwiększyć limit.')],
            ]);
        }

        // If this is the first address, make it default
        $isFirst = $user->addresses()->count() === 0;

        // If setting as default, clear other defaults
        if ($data['is_default'] ?? $isFirst) {
            $user->addresses()->update(['is_default' => false]);
        }

        return $user->addresses()->create([
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'place_id' => $data['place_id'] ?? null,
            'components' => $data['components'] ?? null,
            'nickname' => $data['nickname'] ?? null,
            'is_default' => $data['is_default'] ?? $isFirst,
        ]);
    }

    /**
     * Update existing address.
     *
     * @throws ModelNotFoundException If address doesn't exist
     */
    public function update(UserAddress $address, array $data): UserAddress
    {
        // If setting as default, clear other defaults first
        if ($data['is_default'] ?? false) {
            $address->user->addresses()
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $address->update([
            'address' => $data['address'] ?? $address->address,
            'latitude' => $data['latitude'] ?? $address->latitude,
            'longitude' => $data['longitude'] ?? $address->longitude,
            'place_id' => $data['place_id'] ?? $address->place_id,
            'components' => $data['components'] ?? $address->components,
            'nickname' => $data['nickname'] ?? $address->nickname,
            'is_default' => $data['is_default'] ?? $address->is_default,
        ]);

        return $address->fresh();
    }

    /**
     * Delete address.
     * If deleting default address, make another one default (if exists).
     */
    public function delete(UserAddress $address): bool
    {
        $user = $address->user;
        $wasDefault = $address->is_default;

        $address->delete();

        // If deleted address was default, make another one default
        if ($wasDefault) {
            $newDefault = $user->addresses()->first();
            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return true;
    }

    /**
     * Get address for user, ensuring it belongs to them.
     *
     * @throws ModelNotFoundException If not found or doesn't belong to user
     */
    public function getForUser(User $user, int $addressId): UserAddress
    {
        $address = UserAddress::where('user_id', $user->id)
            ->where('id', $addressId)
            ->first();

        if (! $address) {
            throw new ModelNotFoundException('Address not found.');
        }

        return $address;
    }
}
