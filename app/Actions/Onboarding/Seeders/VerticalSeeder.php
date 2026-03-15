<?php

declare(strict_types=1);

namespace App\Actions\Onboarding\Seeders;

use App\Models\Organization;

interface VerticalSeeder
{
    public function seed(Organization $organization): void;
}
