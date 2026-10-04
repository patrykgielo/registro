<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Keeps the storefront translatable. Every Blade view under resources/views is in scope unless it
 * is listed in EXEMPT with a reason, so a NEW directory is covered without anyone editing this file.
 *
 * What counts as a violation: Polish text (diacritics, plus a short list of unmistakable Polish
 * words that carry none) anywhere in a view that is not (a) inside a Blade / HTML / JS comment or
 * (b) the literal key of a __() / trans() / trans_choice() / @lang() call. That includes text nodes,
 * attributes (alt, title, placeholder, aria-label, value), @php blocks and inline <script> — a
 * Polish string in a `match()` arm or an Alpine x-data object is as untranslatable as one in a <p>.
 *
 * Limits, on purpose: English text and Polish with no diacritics and no word from POLISH_WORDS are
 * invisible to a static check; text that comes from the database or a controller is out of reach.
 * See app/docs/guides/localisation.md ("What stays Polish").
 */
class NoHardcodedPolishInViewsTest extends TestCase
{
    private const POLISH_LETTERS = '/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u';

    /**
     * Polish words that survive without a diacritic. Matched as whole words, case-insensitively, in plain
     * text nodes, in the value of a text-bearing attribute (TEXT_ATTRIBUTES) and in quoted literals that
     * read as text (see literalHasPolishWord()) — so `aria-label="Zamknij"` and `match(...) => 'Dodaj'` are
     * caught too. Words that are also English (`status`, `data`) are here on purpose: the product has a
     * translation key for each, and a bare literal is the regression this test exists to stop.
     */
    private const POLISH_WORDS = [
        'oraz', 'lub', 'jest', 'nie', 'tak', 'dla', 'przy', 'przez', 'zostal', 'zostala', 'zostanie',
        'dodaj', 'usun', 'zapisz', 'anuluj', 'szukaj', 'wyslij', 'zaloguj', 'wyloguj', 'zarejestruj',
        'zamknij', 'otworz', 'wybierz', 'pokaz', 'ukryj', 'nastepny', 'poprzedni', 'wstecz', 'dalej',
        'koszyk', 'zamowienie', 'zamowienia', 'platnosc', 'uslugi', 'uslug', 'telefon', 'adres',
        'ulica', 'miasto', 'nazwisko', 'opublikowano', 'kategoria', 'dostepne', 'niedostepne',
        'razem', 'suma', 'data', 'godzina', 'ilosc', 'cena', 'opis', 'nazwa', 'status',
        'brak', 'kontakt', 'szczegoly', 'powrot', 'wroc', 'dziekujemy', 'prosimy', 'wiadomosc',
    ];

    /** Attributes whose value is read by a person (or a screen reader). */
    private const TEXT_ATTRIBUTES = ['alt', 'title', 'placeholder', 'aria-label', 'label', 'value', 'message', 'subtitle', 'help-text', 'helptext', 'heading', 'description'];

    /**
     * Reason => the exact files (relative to resources/views) that may keep Polish. One entry per FILE, never
     * a directory: a new view next to an exempt one is in scope until someone adds it here with a reason, and
     * test_every_exempt_file_still_contains_polish fails for any file listed that no longer needs the exemption.
     *
     * @var array<string, list<string>>
     */
    private const EXEMPT = [
        'Admin panel stays Polish — product owner decision (localisation.md).' => [
            'filament/components/google-maps-picker.blade.php',
            'filament/components/location-map-picker.blade.php',
            'filament/pages/analytics-overview.blade.php',
            'filament/pages/maintenance-settings.blade.php',
            'filament/pages/maintenance.blade.php',
            'filament/pages/statistics.blade.php',
            'filament/platform/pages/statistics.blade.php',
            'filament/resources/audit-log/view-modal.blade.php',
            'filament/resources/email-send/html-preview.blade.php',
            'filament/widgets/cache-clear.blade.php',
        ],
        'Legacy appointment flow; the product is equipment_rental only and the flow is not reachable.' => [
            'appointments/index.blade.php',
            'booking/create.blade.php',
            'booking-wizard/confirmation.blade.php',
            'booking-wizard/layout.blade.php',
            'booking-wizard/steps/contact.blade.php',
            'booking-wizard/steps/datetime.blade.php',
            'booking-wizard/steps/review.blade.php',
            'booking-wizard/steps/service.blade.php',
            'booking-wizard/steps/vehicle-location.blade.php',
            'components/booking-wizard/bottom-sheet.blade.php',
            'components/booking-wizard/calendar.blade.php',
            'components/booking-wizard/progress-indicator.blade.php',
            'components/booking-wizard/time-grid.blade.php',
        ],
        'Admin statistics PDF (admin panel stays Polish).' => [
            'statistics/pdf-report.blade.php',
        ],
        'Language-specific template by name (the -pl suffix is the language selector); an English twin is a separate file.' => [
            'emails/user-registered-pl.blade.php',
        ],
        'Mail to the platform owner about SMS spending, not to a customer.' => [
            'mail/sms-spending-alert.blade.php',
        ],
    ];

    /**
     * @return array<string, string> file => reason
     */
    private function exemptFiles(): array
    {
        $files = [];

        foreach (self::EXEMPT as $reason => $list) {
            foreach ($list as $file) {
                $files[$file] = $reason;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function views(): array
    {
        $root = resource_path('views');
        $views = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $views[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getPathname();
            }
        }

        ksort($views);

        return $views;
    }

    private function isExempt(string $relative): bool
    {
        return array_key_exists($relative, $this->exemptFiles());
    }

    /**
     * Blanks comments and translation-call literals but keeps every newline, so a hit's line number
     * is the real one in the file.
     */
    private function strip(string $source): string
    {
        $blank = fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]);

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $source = preg_replace_callback('/<!--.*?-->/s', $blank, $source);
        $source = preg_replace_callback('~/\*.*?\*/~s', $blank, $source);
        $source = preg_replace_callback('~(?<=\s)//(?!/)[^\n]*~', $blank, $source);
        $source = preg_replace_callback('~^[ \t]*//[^\n]*~m', $blank, $source);

        // The key of a translation call IS Polish for JSON keys; that is the whole convention.
        return preg_replace_callback(
            '/(?:(?<![\w>:$])(?:__|trans|trans_choice)|@lang)\(\s*([\'"])(?:\\\\.|(?!\1).)*\1/s',
            $blank,
            $source,
        );
    }

    private function hasPolishWord(string $text): bool
    {
        // A hyphenated token (`data-list`, `x-data`) is an identifier or a CSS class, never one Polish word.
        preg_match_all('/(?<![A-Za-z-])[A-Za-z]+(?![A-Za-z-])/', $text, $words);

        foreach ($words[0] as $word) {
            if (in_array(strtolower($word), self::POLISH_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quoted literals on a line that read as text: a phrase (has a space) or a capitalised word, made of
     * plain characters only. Lower-case single words (`'status'` as an array key, `"data"` as a name) are
     * identifiers, not text, and are left alone — unless they sit in a TEXT_ATTRIBUTES value.
     */
    private function literalHasPolishWord(string $line): bool
    {
        $plain = '/^[A-Za-z0-9\s.,:;!?()\/\'\-–—&]+$/u';

        // Attribute values a person reads: any shape counts, even a single lower-case word.
        $attrs = implode('|', array_map('preg_quote', self::TEXT_ATTRIBUTES));
        if (preg_match_all('/(?<![\w-])(?:'.$attrs.')\s*=\s*"([^"]*)"/i', $line, $m)) {
            foreach ($m[1] as $value) {
                $value = trim(preg_replace('/\{\{.*?\}\}/', ' ', $value));
                if ($value !== '' && preg_match('/^[A-Za-z0-9\s.,:;!?\/\-–—&]+$/', $value) && $this->hasPolishWord($value)) {
                    return true;
                }
            }
        }

        $scan = function (string $text) use (&$scan, $plain): bool {
            if (! preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $text, $m, PREG_SET_ORDER)) {
                return false;
            }
            foreach ($m as $hit) {
                $body = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
                if ($body === '') {
                    continue;
                }
                if ($scan($body)) {
                    return true;
                }
                $reads = str_contains(trim($body), ' ') || preg_match('/^[A-Z]/', trim($body));
                if ($reads && preg_match($plain, trim($body)) && $this->hasPolishWord($body)) {
                    return true;
                }
            }

            return false;
        };

        return $scan($line);
    }

    /**
     * @return list<string> "file:line  text"
     */
    private function violations(string $relative, string $path): array
    {
        $lines = explode("\n", $this->strip(file_get_contents($path)));
        $found = [];
        $inCode = false;

        foreach ($lines as $i => $line) {
            $number = $i + 1;
            $hit = "resources/views/{$relative}:{$number}  ".mb_substr(trim($line), 0, 140);

            if (preg_match(self::POLISH_LETTERS, $line)) {
                $found[] = $hit;

                continue;
            }

            // @section('title', 'Moje Konto'): a literal second argument is page text with no diacritic to catch it.
            if (preg_match('/@section\(\s*[\'"][\w-]+[\'"]\s*,\s*[\'"]/', $line)) {
                $found[] = $hit;

                continue;
            }

            // Attribute values and quoted text literals — everywhere, including @php and <script>.
            if ($this->literalHasPolishWord($line)) {
                $found[] = $hit;

                continue;
            }

            // Plain-text-node heuristic; never inside code blocks.
            if (preg_match('/@php\b(?!\()|<script|<style/i', $line) && ! preg_match('/@endphp|<\/script>|<\/style>/i', $line)) {
                $inCode = true;
            }
            if ($inCode) {
                if (preg_match('/@endphp|<\/script>|<\/style>/i', $line)) {
                    $inCode = false;
                }

                continue;
            }

            $text = preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/<[^>]*>/', '/@\w+(\([^)]*\))?/', '/&\w+;/'], ' ', $line);
            // A bare attribute name on its own line (`data-animate`, `x-data`) or a directive is not text.
            if (preg_match('/^\s*(@|:)|^\s*[a-z][\w:.]*-[\w:.-]*\s*>?\s*$|^\s*x-data\b/', $line)) {
                continue;
            }
            if (preg_match('/^[\s\w.,:;!?()\'"\/\-–—]*$/u', (string) $text) && $this->hasPolishWord((string) $text)) {
                $found[] = $hit;
            }
        }

        return $found;
    }

    public function test_no_in_scope_view_contains_polish_text_outside_a_translation_call(): void
    {
        $violations = [];

        foreach ($this->views() as $relative => $path) {
            if (! $this->isExempt($relative)) {
                array_push($violations, ...$this->violations($relative, $path));
            }
        }

        $this->assertSame(
            [],
            $violations,
            count($violations)." hardcoded Polish string(s) in a customer-facing view. Move each into lang/{pl,en}/<group>.php and use __('<group>.<key>') — or, if the file must stay Polish (admin, legacy flow), add it to EXEMPT with a reason (app/docs/guides/localisation.md):\n".implode("\n", $violations),
        );
    }

    public function test_every_exempt_file_still_contains_polish(): void
    {
        $views = $this->views();
        $stale = [];

        foreach ($this->exemptFiles() as $file => $reason) {
            if (! isset($views[$file])) {
                $stale[] = "{$file}: does not exist — delete the entry ({$reason})";
            } elseif ($this->violations($file, $views[$file]) === []) {
                $stale[] = "{$file}: no longer contains hardcoded Polish — delete the entry ({$reason})";
            }
        }

        $this->assertSame([], $stale, "Stale exemptions:\n".implode("\n", $stale));
    }

    /**
     * One case per place a literal can sit, for three shapes of Polish: a word with diacritics, a diacritic-free word
     * from POLISH_WORDS and a diacritic-free phrase. The word list itself is not tested entry by entry.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function diacriticFreeWords(): array
    {
        $cases = [];

        foreach (['Zażółć' => 'diacritics', 'Zamknij' => 'word', 'Wybierz date' => 'phrase'] as $w => $shape) {
            $cases["{$shape} in a text node"] = ["<p>{$w}</p>\n", 'text node'];
            $cases["{$shape} capitalised in an attribute"] = ["<button aria-label=\"{$w}\">x</button>\n", 'attribute'];
            $cases["{$shape} lower-case in an attribute"] = ['<input placeholder="'.strtolower($w)."\">\n", 'attribute'];
            $cases["{$shape} in an @php literal"] = ["@php\n\$label = match (\$x) { 1 => '{$w}', default => '' };\n@endphp\n", '@php'];
            $cases["{$shape} in an inline script"] = ["<script>\nlet label = '{$w}';\n</script>\n", 'script'];
            $cases["{$shape} inside a Blade expression in an attribute"] = ["<button aria-label=\"{{ \$open ? '{$w}' : '' }}\">x</button>\n", 'expression'];
        }

        return $cases;
    }

    #[DataProvider('diacriticFreeWords')]
    public function test_the_detector_flags_diacritic_free_polish_everywhere_a_literal_can_sit(string $source, string $where): void
    {
        $this->assertCount(1, $this->violationsOf($source), "Not flagged ({$where}): ".trim($source));
    }

    /**
     * What must NOT be flagged: identifiers that happen to be a listed word, and the same word once it is a key.
     *
     * @return array<string, array{0: string}>
     */
    public static function cleanSources(): array
    {
        return [
            'session key' => ["@if (session('status'))\n<x-ui.alert :message=\"session('status')\" />\n"],
            'data attribute names' => ["<div\n    data-animate\n    data-consent-error\n    x-data\n>\n"],
            'lower-case array key in @php' => ["@php\n\$s = \$order['status'];\n\$d = \$row['data'];\n@endphp\n"],
            'translated attribute' => ["<button aria-label=\"{{ __('common.close') }}\">x</button>\n"],
            'translated literal in @php' => ["@php\n\$l = __('Zamknij');\n@endphp\n"],
            'english label' => ["<button aria-label=\"Close\">x</button>\n<p>Order placed</p>\n"],
            'class list' => ["<div class=\"flex items-center data-list\">x</div>\n"],
        ];
    }

    #[DataProvider('cleanSources')]
    public function test_the_detector_leaves_identifiers_and_translated_text_alone(string $source): void
    {
        $this->assertSame([], $this->violationsOf($source));
    }

    /**
     * @return list<string>
     */
    private function violationsOf(string $source): array
    {
        $dir = sys_get_temp_dir().'/lint-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $path = "{$dir}/case.blade.php";
        file_put_contents($path, $source);

        try {
            return $this->violations('case.blade.php', $path);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /**
     * The detector must see what it claims to see. These run on strings, not files, so the proof does
     * not depend on whatever the views happen to contain today.
     */
    public function test_the_detector_flags_polish_and_ignores_comments_and_translation_calls(): void
    {
        $dir = sys_get_temp_dir().'/lint-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $cases = [
            'text node' => ["<p>Zamówienie złożone</p>\n", 1],
            'attribute' => ["<input placeholder=\"Wpisz imię\">\n", 1],
            'php block' => ["@php\n\$x = 'Zażółć gęślą';\n@endphp\n", 1],
            'diacritic-free word in a text node' => ["<p>\n    Opublikowano: {{ \$d }}\n</p>\n", 1],
            'line number is the real one' => ["<p>a</p>\n<p>b</p>\n<p>Źle</p>\n", 1],
            'blade comment' => ["{{-- Zażółć gęślą --}}\n<p>ok</p>\n", 0],
            'multi-line blade comment' => ["{{--\n pierwsza linia: ąę\n druga: ść\n--}}\n", 0],
            'html comment' => ["<!-- Zażółć -->\n", 0],
            'js comment' => ["<script>\nlet a = 1; // komentarz: żółć\n</script>\n", 0],
            'translation call' => ["<p>{{ __('Zamówienie złożone') }}</p>\n", 0],
            'dotted translation call' => ["<p>{{ __('orders.index.title') }}</p>\n<p>@lang('Zażółć')</p>\n", 0],
            'trans_choice' => ["{{ trans_choice('common.positions', \$n) }}\n", 0],
            'literal section text' => ["@section('title', 'Moje Konto')\n", 1],
            'translated section text' => ["@section('title', __('profile.index.title'))\n", 0],
            'english only' => ["<p>Order placed</p>\n", 0],
        ];

        try {
            foreach ($cases as $name => [$source, $expected]) {
                $path = "{$dir}/case.blade.php";
                file_put_contents($path, $source);

                $got = $this->violations('case.blade.php', $path);
                $this->assertCount($expected, $got, "Case '{$name}': ".json_encode($got, JSON_UNESCAPED_UNICODE));
            }

            file_put_contents("{$dir}/case.blade.php", "<p>a</p>\n<p>b</p>\n<p>Źle</p>\n");
            $this->assertStringContainsString('case.blade.php:3  <p>Źle</p>', $this->violations('case.blade.php', "{$dir}/case.blade.php")[0]);
        } finally {
            @unlink("{$dir}/case.blade.php");
            @rmdir($dir);
        }
    }
}
