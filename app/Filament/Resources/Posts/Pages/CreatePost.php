<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = PostResource::class;
}
