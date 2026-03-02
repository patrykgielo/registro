<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    // Event type constants
    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_EXPORTED = 'exported';

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    public const EVENT_CONSENT_GRANTED = 'consent_granted';

    public const EVENT_CONSENT_WITHDRAWN = 'consent_withdrawn';

    public const EVENT_PASSWORD_CHANGED = 'password_changed';

    public const EVENT_PASSWORD_RESET = 'password_reset';

    public const EVENT_ACCOUNT_ANONYMIZED = 'account_anonymized';

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Get human-readable event label
     */
    public function getEventLabelAttribute(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'Utworzono',
            self::EVENT_UPDATED => 'Zaktualizowano',
            self::EVENT_DELETED => 'Usunięto',
            self::EVENT_EXPORTED => 'Wyeksportowano',
            self::EVENT_LOGIN => 'Logowanie',
            self::EVENT_LOGOUT => 'Wylogowanie',
            self::EVENT_LOGIN_FAILED => 'Nieudane logowanie',
            self::EVENT_CONSENT_GRANTED => 'Zgoda udzielona',
            self::EVENT_CONSENT_WITHDRAWN => 'Zgoda wycofana',
            self::EVENT_PASSWORD_CHANGED => 'Zmiana hasła',
            self::EVENT_PASSWORD_RESET => 'Reset hasła',
            self::EVENT_ACCOUNT_ANONYMIZED => 'Konto zanonimizowane',
            default => ucfirst($this->event),
        };
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
