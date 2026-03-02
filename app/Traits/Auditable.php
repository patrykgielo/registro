<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->logAudit('created', null, $model->getAuditableAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();

            // Remove timestamps from tracked changes
            unset($changes['updated_at'], $changes['created_at']);

            // Apply auditInclude/auditExclude filters
            $changes = $model->filterAuditFields($changes);

            if (empty($changes)) {
                return;
            }

            // Build old_values with ONLY the changed fields
            $original = $model->getOriginal();
            $oldValues = [];
            foreach (array_keys($changes) as $field) {
                $oldValues[$field] = $original[$field] ?? null;
            }

            $model->logAudit('updated', $oldValues, $changes);
        });

        static::deleted(function ($model) {
            $model->logAudit('deleted', $model->getAuditableAttributes(), null);
        });
    }

    protected function logAudit(string $event, ?array $oldValues, ?array $newValues): void
    {
        // Use REMOTE_ADDR directly to avoid header spoofing
        // Only trust X-Forwarded-For if behind trusted proxy
        $ip = request()->server('REMOTE_ADDR');

        AuditLog::create([
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'user_id' => Auth::id(),
            'ip_address' => $ip,
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Filter fields based on $auditInclude and $auditExclude model properties.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function filterAuditFields(array $fields): array
    {
        $sensitivePatterns = ['password', 'token', 'secret', 'key', 'api_key', 'remember_token'];
        $excludedFields = $this->auditExclude ?? [];
        $includedFields = $this->auditInclude ?? [];

        return array_filter($fields, function ($key) use ($excludedFields, $includedFields, $sensitivePatterns) {
            // If auditInclude is defined, ONLY allow those fields
            if (! empty($includedFields) && ! in_array($key, $includedFields)) {
                return false;
            }

            if (in_array($key, $excludedFields)) {
                return false;
            }

            foreach ($sensitivePatterns as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get all auditable attributes for created/deleted events.
     *
     * @return array<string, mixed>
     */
    protected function getAuditableAttributes(): array
    {
        return $this->filterAuditFields($this->attributesToArray());
    }
}
