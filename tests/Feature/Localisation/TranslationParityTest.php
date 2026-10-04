<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The owner requirement is "polski i angielski, kompletnie" — these tests keep it true. Whatever
 * exists in lang/pl must exist in lang/en and vice versa, with the same placeholders, and a
 * "translation" must not be the other language copied across. See app/docs/guides/localisation.md.
 */
class TranslationParityTest extends TestCase
{
    private const POLISH_LETTERS = '/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u';

    /**
     * @return array<string, array<string, mixed>> relative path (e.g. "validation.php") => dotted key => value
     */
    private function phpFiles(string $locale): array
    {
        $root = lang_path($locale);
        $files = [];

        foreach (glob($root.'/*.php') ?: [] as $path) {
            $files[basename($path)] = Arr::dot(require $path);
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function jsonLines(string $locale): array
    {
        $path = lang_path($locale.'.json');
        $this->assertFileExists($path, "lang/{$locale}.json is missing");

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_]+/', $text, $m);

        return array_values(array_unique($m[0]));
    }

    public function test_both_languages_have_the_same_php_translation_files(): void
    {
        $this->assertSame(
            array_keys($this->phpFiles('pl')),
            array_keys($this->phpFiles('en')),
            'lang/pl and lang/en must contain the same set of PHP files.',
        );
    }

    public function test_every_php_file_has_the_same_keys_in_both_languages(): void
    {
        $pl = $this->phpFiles('pl');
        $en = $this->phpFiles('en');
        $problems = [];

        foreach (array_intersect_key($pl, $en) as $file => $plKeys) {
            foreach (array_diff_key($plKeys, $en[$file]) as $key => $_) {
                $problems[] = "{$file}: '{$key}' exists only in pl";
            }
            foreach (array_diff_key($en[$file], $plKeys) as $key => $_) {
                $problems[] = "{$file}: '{$key}' exists only in en";
            }
        }

        $this->assertSame([], $problems, "Key sets differ between lang/pl and lang/en:\n".implode("\n", $problems));
    }

    public function test_json_files_have_the_same_keys_in_both_languages(): void
    {
        $pl = $this->jsonLines('pl');
        $en = $this->jsonLines('en');

        $problems = [
            ...array_map(fn ($k) => "'{$k}' exists only in pl.json", array_keys(array_diff_key($pl, $en))),
            ...array_map(fn ($k) => "'{$k}' exists only in en.json", array_keys(array_diff_key($en, $pl))),
        ];

        $this->assertSame([], $problems, "pl.json and en.json differ:\n".implode("\n", $problems));
    }

    public function test_placeholders_match_between_languages(): void
    {
        $problems = [];

        $pl = $this->phpFiles('pl');
        $en = $this->phpFiles('en');
        foreach (array_intersect_key($pl, $en) as $file => $plKeys) {
            foreach (array_intersect_key($plKeys, $en[$file]) as $key => $plValue) {
                $this->comparePlaceholders("{$file}:{$key}", (string) $plValue, (string) $en[$file][$key], $problems);
            }
        }

        $plJson = $this->jsonLines('pl');
        foreach (array_intersect_key($plJson, $this->jsonLines('en')) as $key => $plValue) {
            $this->comparePlaceholders("json:{$key}", $plValue, $this->jsonLines('en')[$key], $problems);
        }

        $this->assertSame([], $problems, "Placeholders differ between languages:\n".implode("\n", $problems));
    }

    /**
     * @param  list<string>  $problems
     */
    private function comparePlaceholders(string $where, string $pl, string $en, array &$problems): void
    {
        $a = $this->placeholders($pl);
        $b = $this->placeholders($en);
        sort($a);
        sort($b);

        if ($a !== $b) {
            $problems[] = "{$where}: pl has [".implode(' ', $a).'], en has ['.implode(' ', $b).']';
        }
    }

    public function test_no_translation_is_empty(): void
    {
        $empty = [];

        foreach (['pl', 'en'] as $locale) {
            foreach ($this->phpFiles($locale) as $file => $keys) {
                foreach ($keys as $key => $value) {
                    if (trim((string) $value) === '') {
                        $empty[] = "{$locale}/{$file}: '{$key}'";
                    }
                }
            }
            foreach ($this->jsonLines($locale) as $key => $value) {
                if (trim($value) === '') {
                    $empty[] = "{$locale}.json: '{$key}'";
                }
            }
        }

        $this->assertSame([], $empty, "Empty translations:\n".implode("\n", $empty));
    }

    /**
     * Polish text is the source language of every JSON key, so a Polish sentence copied into
     * en.json would satisfy the key-parity test while giving English users Polish. Polish
     * diacritics in the English files are the cheap tell.
     */
    public function test_english_files_contain_no_polish_text(): void
    {
        $polish = [];

        foreach ($this->phpFiles('en') as $file => $keys) {
            foreach ($keys as $key => $value) {
                if (preg_match(self::POLISH_LETTERS, (string) $value)) {
                    $polish[] = "en/{$file}: '{$key}' => {$value}";
                }
            }
        }
        foreach ($this->jsonLines('en') as $key => $value) {
            if (preg_match(self::POLISH_LETTERS, $value)) {
                $polish[] = "en.json: '{$key}' => {$value}";
            }
        }

        $this->assertSame([], $polish, "Polish text in the English files:\n".implode("\n", $polish));
    }

    /**
     * lang/{pl,en}/validation.php must always carry every rule Laravel itself ships, so a
     * framework upgrade that adds a rule fails here instead of leaking an English default
     * (or a raw "validation.x" key) into the Polish UI.
     */
    public function test_validation_files_cover_every_rule_the_framework_ships(): void
    {
        $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        unset($framework['custom'], $framework['attributes']);
        $expected = array_keys(Arr::dot($framework));
        $missing = [];

        foreach (['pl', 'en'] as $locale) {
            $ours = Arr::dot(require lang_path("{$locale}/validation.php"));
            foreach ($expected as $key) {
                if (! array_key_exists($key, $ours)) {
                    $missing[] = "{$locale}/validation.php lacks '{$key}'";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_auth_passwords_and_pagination_cover_every_framework_key(): void
    {
        $missing = [];

        foreach (['auth', 'passwords', 'pagination'] as $file) {
            $framework = Arr::dot(require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php"));
            foreach (['pl', 'en'] as $locale) {
                $ours = Arr::dot(require lang_path("{$locale}/{$file}.php"));
                foreach (array_keys($framework) as $key) {
                    if (! array_key_exists($key, $ours)) {
                        $missing[] = "{$locale}/{$file}.php lacks '{$key}'";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }
}
