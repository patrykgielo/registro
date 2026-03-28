<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'order_id',
        'organization_id',
        'p24_session_id',
        'p24_order_id',
        'amount',
        'currency',
        'status',
        'webhook_payload',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'webhook_payload' => 'array',
            'verified_at' => 'datetime',
            'amount' => 'integer',
            'status' => 'string',
        ];
    }

    /**
     * @return BelongsTo<\App\Models\Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo('App\Models\Order');
    }
}
