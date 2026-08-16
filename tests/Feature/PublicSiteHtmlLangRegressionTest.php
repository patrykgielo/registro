<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the fix for feature/tenant-branding-fixes: <html lang> already
 * derived from app()->getLocale() (resources/views/layouts/app.blade.php),
 * but config('app.locale') fell back to Laravel's stock 'en' whenever
 * APP_LOCALE was not explicitly set in the environment — an environment
 * that omitted it served entirely-Polish content mislabeled as English
 * (confirmed on the live site via curl). This test pins the config default,
 * not a hardcoded 'pl' in the template — the template must keep following
 * app()->getLocale(), never a literal 'pl'.
 */
class PublicSiteHtmlLangRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The test process itself always has APP_LOCALE=pl via .env.testing
     * (see .claude/rules/tests.md), which would mask a regression to
     * config/app.php's fallback if we just read config('app.locale').
     * Re-require the config file with the env var genuinely unset instead,
     * to prove what an environment that forgot to set APP_LOCALE gets.
     */
    public function test_locale_config_defaults_to_polish_when_env_var_is_unset(): void
    {
        $original = getenv('APP_LOCALE');
        putenv('APP_LOCALE');
        unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);

        try {
            $freshConfig = require config_path('app.php');
            $this->assertSame('pl', $freshConfig['locale']);
        } finally {
            putenv("APP_LOCALE={$original}");
            $_ENV['APP_LOCALE'] = $original;
            $_SERVER['APP_LOCALE'] = $original;
        }
    }

    public function test_root_domain_html_tag_declares_polish_locale(): void
    {
        $response = $this->get('http://registro.local/')->assertOk();

        $response->assertSee('<html lang="pl"', false);
        $response->assertDontSee('<html lang="en"', false);
    }
}
