<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Blade;

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
     * Get the list of available variables for this template.
     */
    public function getAvailableVariables(): array
    {
        return $this->variables ?? [];
    }

    /**
     * Render the message body with the provided data.
     *
     * Uses Blade's compileString to render template with variables.
     *
     * @param  array  $data  Key-value pairs to replace in template
     * @return string Rendered message content
     */
    public function render(array $data): string
    {
        // Replace {{variable}} placeholders with Blade syntax
        $template = $this->message_body;

        // Convert {{variable}} to {{ $variable }}
        $template = preg_replace('/\{\{(\w+)\}\}/', '{{ $\1 }}', $template);

        // Compile and render the Blade template
        try {
            return Blade::render($template, $data);
        } catch (\Exception $e) {
            // Fallback to simple string replacement if Blade rendering fails
            return $this->simpleRender($data);
        }
    }

    /**
     * Simple string replacement for rendering (fallback).
     */
    protected function simpleRender(array $data): string
    {
        $content = $this->message_body;

        foreach ($data as $key => $value) {
            $content = str_replace('{{'.$key.'}}', (string) $value, $content);
        }

        return $content;
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
