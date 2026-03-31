<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ServiceType;

trait HasRentalBehavior
{
    protected static function bootHasRentalBehavior(): void
    {
        static::creating(function (self $model) {
            if (isset($model->service_type) && $model->service_type !== ServiceType::ItemRental) {
                $model->price_on_request = false;
            }
        });

        static::updating(function (self $model) {
            if (isset($model->service_type) && $model->service_type !== ServiceType::ItemRental) {
                $model->price_on_request = false;
            }
        });
    }

    public function isRentalPriceOnRequest(): bool
    {
        return $this->service_type === ServiceType::ItemRental
            && (bool) $this->price_on_request;
    }
}
