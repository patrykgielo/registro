<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TenantFeature;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SMS Template Model
 *
 * Stores SMS templates with support for multiple languages and Blade rendering.
 *
 * @property int $id
 * @property string $key Template identifier (e.g., 'appointment-reminder-24h')
 * @property string $language Language code: 'pl', 'en'
 * @property string $message_body SMS message template with {{placeholders}}
 * @property array $variables Available variables for template
 * @property int $max_length Maximum SMS length (160 for GSM, 70 for Unicode)
 * @property bool $active Template is active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SmsTemplate extends Model
{
    use BelongsToOrganization;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sms_templates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'key',
        'language',
        'message_body',
        'variables',
        'max_length',
        'active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'variables' => 'array',
        'active' => 'boolean',
        'max_length' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all SMS sends using this template.
     */
    public function smsSends(): HasMany
    {
        return $this->hasMany(SmsSend::class, 'template_key', 'key');
    }

    /**
     * Scope a query to only include active templates.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to filter by template key.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Scope a query to filter by language.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Resolve the active template to actually send, tenant-override-aware.
     *
     * Same rationale and cross-tenant guarantees as EmailTemplate::resolveActive() —
     * see that docblock. SmsTemplate carries the identical BelongsToOrganization +
     * NULL-org-seeded-global combination, so it has the identical defect.
     */
    public static function resolveActive(string $key, string $language): ?self
    {
        $tenantId = TenantFeature::currentTenant()?->id;

        return static::query()
            ->withoutGlobalScope('organization')
            ->where('key', $key)
            ->where('language', $language)
            ->where('active', true)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('organization_id');

                if ($tenantId !== null) {
                    $query->orWhere('organization_id', $tenantId);
                }
            })
            ->orderByRaw('organization_id IS NULL')
            ->first();
    }

    /**
     * Get the list of available variables for this template.
     */
    public function getAvailableVariables(): array
    {
        return $this->variables ?? [];
    }

    /**
     * Render the message body with the provided data.
     *
     * SECURITY: this is plain string substitution only — the template body is NEVER
     * compiled or executed as PHP/Blade (was a critical SSTI/RCE vector, since
     * message_body is editable by tenant-level admins, not just super-admins). Only
     * literal {{key}} tokens are replaced; everything else in the body (including
     * Blade-looking directives like `@php`) is left as inert literal text. No HTML
     * escaping is applied — SMS is plain text, not HTML.
     *
     * @param  array  $data  Key-value pairs to replace in template
     * @return string Rendered message content
     */
    public function render(array $data): string
    {
        return $this->substitutePlaceholders($this->message_body, $data);
    }

    /**
     * Simple, non-executing string replacement.
     *
     * Only exact `{{key}}` tokens (word characters, no spaces/expressions) are replaced.
     * Unknown tokens are left untouched. This never invokes Blade/eval — it cannot execute
     * PHP embedded in a template body.
     */
    protected function substitutePlaceholders(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($data) {
            $key = $matches[1];

            if (! array_key_exists($key, $data)) {
                return $matches[0];
            }

            return (string) $data[$key];
        }, $template);
    }

    /**
     * Check if the message exceeds maximum length.
     *
     * @param  string  $message  Rendered message
     * @return bool True if message exceeds max_length
     */
    public function exceedsMaxLength(string $message): bool
    {
        return mb_strlen($message) > $this->max_length;
    }

    /**
     * Truncate message to maximum length if needed.
     *
     * @param  string  $message  Message to truncate
     * @param  string  $suffix  Suffix to add if truncated (e.g., '...')
     * @return string Truncated message
     */
    public function truncateMessage(string $message, string $suffix = '...'): string
    {
        if (! $this->exceedsMaxLength($message)) {
            return $message;
        }

        $maxLen = $this->max_length - mb_strlen($suffix);

        return mb_substr($message, 0, $maxLen).$suffix;
    }
}
