<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use Illuminate\Translation\Translator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every translation key the code asks for must resolve in BOTH languages. Keys are collected
 * statically from __() / trans() / trans_choice() / @lang() / Lang::get() with a literal first
 * argument in app/, resources/views/ and routes/.
 *
 * Two key styles exist (see app/docs/guides/localisation.md):
 *  - dotted ("validation.custom.x", "rules.nip.length", "navigation.groups.users") — lang/<locale>/*.php;
 *  - JSON — the Polish sentence IS the key, in lang/pl.json and lang/en.json.
 *
 * Lang::has() cannot be used for JSON keys: pl.json maps a key to itself, and has() treats
 * "value === key" as "not translated". The JSON file is therefore read directly.
 */
class TranslationKeyCoverageTest extends TestCase
{
    private const LOCALES = ['pl', 'en'];

    /**
     * Keys used by Laravel's own views (paginator, mail footer). resources/views/vendor/mail
     * is scanned, the paginator is not published — both are pinned here. They are English at
     * the source, so pl.json must carry the real Polish text.
     */
    private const ENGLISH_SOURCE_KEYS = [
        'All rights reserved.',
        'Pagination Navigation',
        'Showing',
        'to',
        'of',
        'results',
        'Go to page :page',
    ];

    /**
     * Calls whose argument is a variable, so the key cannot be verified statically, as exact
     * "file:line" (a whole-file allowance would hide new dynamic calls in the same file). Add here
     * only with a reason; prefer a literal key.
     */
    private const DYNAMIC_ALLOWED = [
        // Password broker status — resolves passwords.{reset,sent,throttled,token,user}, whose
        // key set is pinned by TranslationParityTest::test_auth_passwords_and_pagination_*.
        'app/Http/Controllers/Auth/ResetPasswordController.php:43',
    ];

    // A leading backslash is allowed on purpose: \__(), \trans(), \Lang::get() and a fully
    // qualified \Illuminate\Support\Facades\Lang::get() are real calls too.
    private const CALL = '(?<![\w>:$])(?:__|trans|trans_choice|@lang|Lang::get|Lang::has)\(\s*';

    /**
     * @return array<string, string> key => "file:line" of first use
     */
    private function usedKeys(array &$dynamic = []): array
    {
        $base = base_path();
        $keys = [];

        foreach (['app', 'resources/views', 'routes', 'config', 'database', 'bootstrap'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $path = str_replace($base.'/', '', $file->getPathname());

                // app/vendor and app/docs are not application code (the former is gitignored).
                if (! str_ends_with($path, '.php') || str_starts_with($path, 'app/vendor/') || str_starts_with($path, 'app/docs/')) {
                    continue;
                }

                $code = file_get_contents($file->getPathname());

                preg_match_all('/'.self::CALL.'(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($m as $hit) {
                    $key = ($hit[1][1] ?? -1) >= 0 && $hit[1][0] !== ''
                        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $hit[1][0])
                        : stripcslashes($hit[2][0] ?? '');

                    if ($key === '') {
                        continue;
                    }
                    if (str_contains($key, '$') || str_contains($key, '{$')) {
                        $dynamic[$path.':'.(substr_count(substr($code, 0, $hit[0][1]), "\n") + 1)] = $key;

                        continue;
                    }
                    $keys[$key] ??= $path.':'.(substr_count(substr($code, 0, $hit[0][1]), "\n") + 1);
                }

                preg_match_all('/'.self::CALL.'(?![\'")\s])/', $code, $d, PREG_OFFSET_CAPTURE);
                foreach ($d[0] as $hit) {
                    if (preg_match('/function\s+$/', substr($code, max(0, $hit[1] - 12), 12))) {
                        continue;
                    }
                    $dynamic[$path.':'.(substr_count(substr($code, 0, $hit[1]), "\n") + 1)] = trim(substr($code, $hit[1], 40));
                }
            }
        }

        ksort($keys);

        return $keys;
    }

    private function isDottedKey(string $key): bool
    {
        return (bool) preg_match('/^([a-z0-9_-]+::)?[a-z0-9_-]+(\.[a-z0-9_-]+)+$/i', $key);
    }

    private function resolves(string $key, string $locale): bool
    {
        /** @var Translator $translator */
        $translator = $this->app['translator'];

        if ($this->isDottedKey($key)) {
            return $translator->has($key, $locale, false);
        }

        return array_key_exists($key, $translator->getLoader()->load($locale, '*', '*'));
    }

    public function test_every_translation_key_used_in_code_resolves_in_both_languages(): void
    {
        $keys = $this->usedKeys();
        $this->assertNotEmpty($keys, 'The key scanner found nothing — its pattern is broken.');

        $missing = [];
        foreach ($keys as $key => $where) {
            foreach (self::LOCALES as $locale) {
                if (! $this->resolves($key, $locale)) {
                    $missing[] = "[{$locale}] '{$key}'  ({$where})";
                }
            }
        }

        $this->assertSame([], $missing, count($missing)." translation key(s) do not resolve:\n".implode("\n", $missing));
    }

    public function test_translation_keys_in_code_are_literals(): void
    {
        $dynamic = [];
        $this->usedKeys($dynamic);

        $offending = [];
        foreach ($dynamic as $where => $snippet) {
            if (! in_array($where, self::DYNAMIC_ALLOWED, true)) {
                $offending[] = "{$where}  {$snippet}";
            }
        }

        $this->assertSame([], $offending, "A computed translation key cannot be checked for both languages. Use a literal key (e.g. build the array with __('…') per entry):\n".implode("\n", $offending));
    }

    public function test_keys_written_in_english_at_the_source_have_a_real_polish_translation(): void
    {
        $translator = $this->app['translator'];
        $pl = $translator->getLoader()->load('pl', '*', '*');
        $en = $translator->getLoader()->load('en', '*', '*');
        $problems = [];

        foreach (self::ENGLISH_SOURCE_KEYS as $key) {
            if (! array_key_exists($key, $pl) || ! array_key_exists($key, $en)) {
                $problems[] = "'{$key}' must exist in pl.json and en.json";
            } elseif ($pl[$key] === $key) {
                $problems[] = "'{$key}' is not translated in pl.json";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
