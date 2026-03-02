<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'consent_type',
        'action',
        'ip_address',
        'user_agent',
        'source',
        'consent_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record consent action in audit trail.
     *
     * @param  User  $user  The user granting/revoking consent
     * @param  string  $type  Consent type (sms, email_marketing, etc.)
     * @param  string  $action  Action (granted, revoked)
     */
    public static function recordConsent(User $user, string $type, string $action): self
    {
        return self::create([
            'user_id' => $user->id,
            'consent_type' => $type,
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            // Note: created_at timestamp serves as consented_at
        ]);
    }
}
