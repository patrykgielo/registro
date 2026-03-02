<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Blade;

/**
 * Email Template Model
 *
 * Stores email templates with support for multiple languages and Blade rendering.
 *
 * @property int $id
 * @property string $key Template identifier (e.g., 'user-registered')
 * @property string $language Language code: 'pl', 'en'
 * @property string $subject Email subject with {{placeholders}}
 * @property string $html_body HTML template content with Blade syntax
 * @property string|null $text_body Plain text version
 * @property string|null $blade_path Fallback Blade file path
 * @property array $variables Available variables for template
 * @property bool $active Template is active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EmailTemplate extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'language',
        'subject',
        'html_body',
        'text_body',
        'blade_path',
        'variables',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all email sends using this template.
     */
    public function emailSends(): HasMany
    {
        return $this->hasMany(EmailSend::class, 'template_key', 'key');
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
     * Render the HTML body with the provided data.
     *
     * Uses Blade's compileString to render template with variables.
     *
     * @param  array  $data  Key-value pairs to replace in template
     * @return string Rendered HTML content
     */
    public function render(array $data): string
    {
        // Replace {{variable}} placeholders with Blade syntax
        $template = $this->html_body;

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
        $content = $this->html_body;

        foreach ($data as $key => $value) {
            $content = str_replace('{{'.$key.'}}', (string) $value, $content);
        }

        return $content;
    }

    /**
     * Render the subject line with the provided data.
     */
    public function renderSubject(array $data): string
    {
        $subject = $this->subject;

        foreach ($data as $key => $value) {
            $subject = str_replace('{{'.$key.'}}', (string) $value, $subject);
        }

        return $subject;
    }

    /**
     * Render the plain text body with the provided data.
     */
    public function renderText(array $data): ?string
    {
        if (! $this->text_body) {
            return null;
        }

        $text = $this->text_body;

        foreach ($data as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }
}
