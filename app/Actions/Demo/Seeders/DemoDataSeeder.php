<?php

declare(strict_types=1);

namespace App\Actions\Demo\Seeders;

use App\Models\Organization;

interface DemoDataSeeder
{
    public function seed(Organization $org): void;

    public function clear(Organization $org): void;

    public function hasData(Organization $org): bool;
}
