<?php

declare(strict_types=1);

namespace App\Filament\Resources\PortfolioItems\Pages;

use App\Filament\Resources\PortfolioItems\PortfolioItemResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use Filament\Resources\Pages\CreateRecord;

class CreatePortfolioItem extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = PortfolioItemResource::class;
}
