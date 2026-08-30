<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * Turns the free-text `opening_hours` repeater (LocationForm.php:105-118 — two
 * plain TextInputs, "np. Pon–Pt" / "np. 7:00–17:00 lub »Zamknięte«") into
 * schema.org `OpeningHoursSpecification` entries, but ONLY when both the day
 * label and the hour range are unambiguous. Anything else (»Dni robocze«,
 * »Cały tydzień«, »na telefon«, »Zamknięte«, wrap-around ranges like
 * »Pt-Pon«) is silently skipped — wrong opening hours in Google are worse
 * than missing ones (a customer driving to a closed branch).
 *
 * Recognised as unambiguous:
 *  - Single day, Polish, with or without diacritics/trailing dot:
 *    poniedziałek/pon, wtorek/wt, środa/sr, czwartek/czw, piątek/pt,
 *    sobota/sob, niedziela/niedz/nie/ndz.
 *  - A forward range of two such days ("Pon-Pt", "Pon – Sob", "Pon do Pt")
 *    where the start day is not later in the week than the end day —
 *    expanded to every day in between. Wrap-around ranges ("Pt-Pon") are
 *    rejected, not interpreted.
 *  - Hours as `H[:MM]-H[:MM]` (any of -, –, — as separator, optional
 *    surrounding whitespace), each side a valid 00-23:00-59 time —
 *    "7-17", "7:00–17:00", "07:00 - 19:00" all match. Anything without a
 *    parseable second boundary ("Zamknięte", "na telefon") does not.
 *
 * NOT interpreted, and deliberately so: "Weekend", "Dni robocze", "Cały
 * tydzień" (no literal day token to resolve) and "Zamknięte" as an hours
 * value (not a time range — the day is simply omitted from the structured
 * data rather than guessed at, which correctly matches "no data" rather
 * than fabricating a closed-day specification schema.org would need a
 * separate shape for).
 */
final class OpeningHoursParser
{
    private const DAY_MAP = [
        'poniedzialek' => 'Monday',
        'pon' => 'Monday',
        'wtorek' => 'Tuesday',
        'wt' => 'Tuesday',
        'sroda' => 'Wednesday',
        'sr' => 'Wednesday',
        'czwartek' => 'Thursday',
        'czw' => 'Thursday',
        'piatek' => 'Friday',
        'pt' => 'Friday',
        'sobota' => 'Saturday',
        'sob' => 'Saturday',
        'niedziela' => 'Sunday',
        'niedz' => 'Sunday',
        'nie' => 'Sunday',
        'ndz' => 'Sunday',
    ];

    private const WEEK_ORDER = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @param  array<int, array{label?: string, hours?: string}>  $entries
     * @return array<int, array{'@type': string, dayOfWeek: string, opens: string, closes: string}>
     */
    public static function parseSpecifications(array $entries): array
    {
        $specifications = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $days = self::resolveDays((string) ($entry['label'] ?? ''));
            $timeRange = self::splitTimeRangeForDisplay((string) ($entry['hours'] ?? ''));

            if ($days === null || $timeRange === null) {
                continue;
            }

            foreach ($days as $day) {
                $specifications[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/'.$day,
                    'opens' => $timeRange['openIso'],
                    'closes' => $timeRange['closeIso'],
                ];
            }
        }

        return $specifications;
    }

    /**
     * Whether a raw `hours` value is an unambiguous time range — used by the
     * card view to decide whether to mark the text up with `<time>`,
     * independent of whether the paired `label` also resolves (a card can
     * legitimately show a `<time>`-wrapped range next to a day label that
     * still isn't confident enough for JSON-LD, e.g. a typo'd day name).
     */
    public static function isUnambiguousTimeRange(string $hours): bool
    {
        return self::splitTimeRangeForDisplay($hours) !== null;
    }

    /**
     * @return array{openRaw: string, closeRaw: string, separator: string, openIso: string, closeIso: string}|null
     */
    private static function splitTimeRangeForDisplay(string $hours): ?array
    {
        $trimmed = trim($hours);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match(
            '/^(\d{1,2}(?::\d{2})?)\s*([\-\x{2013}\x{2014}])\s*(\d{1,2}(?::\d{2})?)$/u',
            $trimmed,
            $matches
        )) {
            return null;
        }

        $openIso = self::normalizeTime($matches[1]);
        $closeIso = self::normalizeTime($matches[3]);

        if ($openIso === null || $closeIso === null) {
            return null;
        }

        return [
            'openRaw' => $matches[1],
            'closeRaw' => $matches[3],
            'separator' => $matches[2],
            'openIso' => $openIso,
            'closeIso' => $closeIso,
        ];
    }

    private static function normalizeTime(string $value): ?string
    {
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = isset($matches[2]) ? (int) $matches[2] : 0;

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array<int, string>|null
     */
    private static function resolveDays(string $label): ?array
    {
        $normalized = self::normalizeDayToken($label);

        if ($normalized === '') {
            return null;
        }

        if (isset(self::DAY_MAP[$normalized])) {
            return [self::DAY_MAP[$normalized]];
        }

        if (! preg_match('/^(.+?)\s*(?:[\-\x{2013}\x{2014}]|do)\s*(.+)$/u', $normalized, $matches)) {
            return null;
        }

        $start = self::DAY_MAP[$matches[1]] ?? null;
        $end = self::DAY_MAP[$matches[2]] ?? null;

        if ($start === null || $end === null) {
            return null;
        }

        $startIndex = array_search($start, self::WEEK_ORDER, true);
        $endIndex = array_search($end, self::WEEK_ORDER, true);

        if ($startIndex > $endIndex) {
            // Wrap-around range (e.g. "Pt-Pon") — not interpreted, see class docblock.
            return null;
        }

        return array_slice(self::WEEK_ORDER, $startIndex, $endIndex - $startIndex + 1);
    }

    private static function normalizeDayToken(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = rtrim($value, '.');

        return strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
    }
}
