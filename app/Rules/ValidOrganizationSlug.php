<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidOrganizationSlug implements ValidationRule
{
    /**
     * Reserved slugs that cannot be used as organization subdomains.
     * These conflict with system routes, infrastructure, or common services.
     */
    private const RESERVED_SLUGS = [
        // Infrastructure
        'www', 'api', 'app', 'cdn', 'static', 'assets', 'media',
        'img', 'images', 'video', 'ns1', 'ns2', 'vpn', 'ssh', 'git',
        // Email
        'mail', 'smtp', 'imap', 'pop', 'pop3', 'ftp',
        // Application routes
        'admin', 'platform', 'health', 'livewire', 'filament',
        'horizon', 'storage', 'sanctum',
        // Business pages
        'status', 'billing', 'pay', 'support', 'help', 'docs',
        'blog', 'shop', 'store', 'contact', 'about', 'legal',
        'terms', 'privacy', 'info',
        // Environment
        'dev', 'staging', 'test', 'demo', 'beta', 'sandbox',
        'default', 'localhost',
        // Brand
        'registro',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = strtolower(trim($value));

        // Must be lowercase alphanumeric and hyphens only
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/', $slug) && ! preg_match('/^[a-z0-9]{1,2}$/', $slug)) {
            $fail('Slug musi zawierać tylko małe litery, cyfry i myślniki. Nie może zaczynać ani kończyć się myślnikiem. Długość: 1-63 znaki.');

            return;
        }

        // Minimum 3 characters for subdomains (practical minimum)
        if (strlen($slug) < 3) {
            $fail('Slug musi mieć minimum 3 znaki.');

            return;
        }

        // Maximum 63 characters (DNS label limit)
        if (strlen($slug) > 63) {
            $fail('Slug nie może przekraczać 63 znaków.');

            return;
        }

        // No double hyphens (confusing and can conflict with punycode)
        if (str_contains($slug, '--')) {
            $fail('Slug nie może zawierać podwójnych myślników.');

            return;
        }

        // Check reserved words
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $fail('Ten slug jest zarezerwowany i nie może być użyty.');

            return;
        }
    }

    /**
     * Get the list of reserved slugs (for external use, e.g. in tests).
     */
    public static function reservedSlugs(): array
    {
        return self::RESERVED_SLUGS;
    }
}
