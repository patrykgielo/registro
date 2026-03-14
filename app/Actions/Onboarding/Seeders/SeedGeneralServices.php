<?php

declare(strict_types=1);

namespace App\Actions\Onboarding\Seeders;

use App\Models\Organization;
use App\Models\Service;

class SeedGeneralServices implements VerticalSeeder
{
    public function seed(Organization $organization): void
    {
        Service::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'name' => 'Przykładowa usługa',
            'description' => 'Opis Twojej usługi. Kliknij "Edytuj" aby dostosować nazwę, cenę i czas trwania.',
            'price' => 100,
            'duration_minutes' => 60,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
