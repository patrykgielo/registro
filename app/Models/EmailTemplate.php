<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Email\TrustedHtml;
use App\Support\TenantFeature;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    use BelongsToOrganization;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
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
     * Resolve the active template to actually send, tenant-override-aware.
     *
     * `BelongsToOrganization`'s global scope restricts every query to
     * `organization_id = <current tenant>` the instant a tenant is resolved — but every
     * seeded template is global (organization_id NULL), so that scope alone makes them
     * unreachable from any tenant-scoped request. This bypasses that scope deliberately
     * (`withoutGlobalScope`) and replaces it with an explicit, narrower one: rows must
     * belong to the CURRENT tenant OR be global (NULL). A tenant-specific override is
     * preferred over the global fallback when both exist. No other tenant's row can ever
     * be matched — the `orWhere('organization_id', $tenantId)` only ever adds the caller's
     * own id, never anyone else's.
     *
     * Console/queue-worker context: TenantFeature::currentTenant() has no request or
     * Filament tenant to resolve there (see its docblock), so $tenantId is null and only
     * global templates match. That is a deliberate, accepted limitation, not an oversight —
     * per-tenant email overrides do not yet apply to queued notification sends.
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
            // Tenant-specific row (organization_id NOT NULL) wins over the global fallback.
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
     * Render the HTML body with the provided data.
     *
     * SECURITY: this is plain string substitution only — the template body is NEVER
     * compiled or executed as PHP/Blade (was a critical SSTI/RCE vector, since
     * html_body is editable by tenant-level admins, not just super-admins). Only
     * literal {{key}} tokens are replaced; everything else in the body (including
     * Blade-looking directives like `@php`) is left as inert literal text.
     * Substituted values are HTML-escaped since html_body is rendered as email HTML —
     * UNLESS a value is a TrustedHtml instance, see substitutePlaceholders().
     *
     * @param  array  $data  Key-value pairs to replace in template
     * @return string Rendered HTML content
     */
    public function render(array $data): string
    {
        return $this->substitutePlaceholders($this->html_body, $data, escape: true);
    }

    /**
     * Simple, non-executing string replacement shared by render() and other renderers.
     *
     * Only exact `{{key}}` tokens (word characters, no spaces/expressions) are replaced.
     * Unknown tokens are left untouched. This never invokes Blade/eval — it cannot execute
     * PHP embedded in a template body.
     *
     * A TrustedHtml value (see that class for the trust model) is the one value type exempt
     * from `e()` when $escape is true — it is inserted into html_body verbatim. Every other
     * value, including a TrustedHtml one reached with $escape false (renderSubject/renderText —
     * plain subject/text, no legitimate markup in either), is neutralised: normal values via
     * e()/no-op-cast same as before, TrustedHtml via strip_tags() so its markup can never leak
     * into a context that was never meant to render HTML.
     */
    protected function substitutePlaceholders(string $template, array $data, bool $escape): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($data, $escape) {
            $key = $matches[1];

            if (! array_key_exists($key, $data)) {
                return $matches[0];
            }

            $raw = $data[$key];

            if ($raw instanceof TrustedHtml) {
                return $escape ? $raw->html : strip_tags($raw->html);
            }

            $value = (string) $raw;

            return $escape ? e($value) : $value;
        }, $template);
    }

    /**
     * Render the subject line with the provided data. No legitimate markup in a subject
     * line — everything, including a TrustedHtml value, is neutralised (see
     * substitutePlaceholders()).
     *
     * SECURITY: sanitized on the FINAL rendered string, not per substituted value — a
     * substituted value (e.g. a customer-supplied name) carrying CR/LF is a header-injection
     * vector (a mailer that does not neutralise it, unlike Symfony Mime today, would split
     * that into a second header), but so is a newline pasted directly into the subject
     * TEMPLATE by a tenant admin. Only sanitizing $data would miss the latter.
     */
    public function renderSubject(array $data): string
    {
        return $this->sanitizeSubject($this->substitutePlaceholders($this->subject, $data, escape: false));
    }

    /**
     * Strip C0 control characters (including CR/LF/TAB) and DEL from a rendered subject line.
     * A run of control characters collapses to a single space rather than one-for-one, so
     * "a\r\n\r\nb" becomes "a b", not "a    b". Byte-range regex, no /u — these are all
     * single-byte ASCII values that never appear inside a multi-byte UTF-8 sequence, so this
     * is safe on any valid UTF-8 subject (Polish diacritics included).
     */
    private function sanitizeSubject(string $subject): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject));
    }

    /**
     * Render the plain text body with the provided data. No legitimate markup in a plain-text
     * body — everything, including a TrustedHtml value, is neutralised (see
     * substitutePlaceholders()).
     */
    public function renderText(array $data): ?string
    {
        if (! $this->text_body) {
            return null;
        }

        return $this->substitutePlaceholders($this->text_body, $data, escape: false);
    }
}
