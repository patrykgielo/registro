<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\User;

class DataExportService
{
    /**
     * Export all user data for GDPR Article 20 (Data Portability)
     */
    public function exportUserData(User $user): array
    {
        // Refresh user to ensure exists
        $user = $user->fresh();
        if (! $user) {
            throw new \RuntimeException('User no longer exists');
        }

        return [
            'export_info' => [
                'generated_at' => now()->toIso8601String(),
                'user_id' => $user->id,
                'format' => 'JSON',
                'locale' => app()->getLocale(),
                'gdpr_article' => 'Article 20 - Right to Data Portability',
            ],
            'personal_data' => $this->getPersonalData($user),
            'addresses' => $this->getAddresses($user),
            'vehicles' => $this->getVehicles($user),
            'appointments' => $this->getAppointments($user),
            'consents' => $this->getConsents($user),
        ];
    }

    protected function getPersonalData(User $user): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'created_at' => $user->created_at?->toIso8601String(),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }

    protected function getAddresses(User $user): array
    {
        return $user->addresses?->map(fn ($address) => [
            'street' => $address->street,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'is_default' => $address->is_default,
        ])->toArray() ?? [];
    }

    protected function getVehicles(User $user): array
    {
        return $user->vehicles?->map(fn ($vehicle) => [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
        ])->toArray() ?? [];
    }

    protected function getAppointments(User $user): array
    {
        return $user->appointments?->map(fn ($apt) => [
            'id' => $apt->id,
            'service' => $apt->service?->name,
            'date' => $apt->appointment_date?->toIso8601String(),
            'status' => $apt->status,
            'total_price' => $apt->total_price,
            'created_at' => $apt->created_at?->toIso8601String(),
        ])->toArray() ?? [];
    }

    protected function getConsents(User $user): array
    {
        return $user->consents?->map(fn ($consent) => [
            'type' => $consent->consent_type,
            'action' => $consent->action,
            'given_at' => $consent->consented_at?->toIso8601String(),
        ])->toArray() ?? [];
    }
}
