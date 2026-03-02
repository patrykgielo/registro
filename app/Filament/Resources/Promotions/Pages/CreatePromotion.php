<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotion extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = PromotionResource::class;
}
