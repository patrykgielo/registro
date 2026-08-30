<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Seo\OpeningHoursParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the exact grammar OpeningHoursParser accepts for the free-text
 * `opening_hours` repeater (LocationForm.php:105-118 — two plain
 * TextInputs, no format enforced at input time). No database needed —
 * pure string parsing.
 */
class OpeningHoursParserTest extends TestCase
{
    public static function unambiguousEntryProvider(): array
    {
        return [
            'day range, en dash hours' => [
                [['label' => 'Pon–Pt', 'hours' => '7:00–17:00']],
                [
                    ['dayOfWeek' => 'https://schema.org/Monday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Tuesday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Wednesday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Thursday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Friday', 'opens' => '07:00', 'closes' => '17:00'],
                ],
            ],
            'hyphen range, spaced times' => [
                [['label' => 'Pon-Pt', 'hours' => '07:00 - 19:00']],
                [
                    ['dayOfWeek' => 'https://schema.org/Monday', 'opens' => '07:00', 'closes' => '19:00'],
                    ['dayOfWeek' => 'https://schema.org/Tuesday', 'opens' => '07:00', 'closes' => '19:00'],
                    ['dayOfWeek' => 'https://schema.org/Wednesday', 'opens' => '07:00', 'closes' => '19:00'],
                    ['dayOfWeek' => 'https://schema.org/Thursday', 'opens' => '07:00', 'closes' => '19:00'],
                    ['dayOfWeek' => 'https://schema.org/Friday', 'opens' => '07:00', 'closes' => '19:00'],
                ],
            ],
            'day range expands to every day between' => [
                [['label' => 'Pon-Pt', 'hours' => '07:00-17:00']],
                [
                    ['dayOfWeek' => 'https://schema.org/Monday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Tuesday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Wednesday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Thursday', 'opens' => '07:00', 'closes' => '17:00'],
                    ['dayOfWeek' => 'https://schema.org/Friday', 'opens' => '07:00', 'closes' => '17:00'],
                ],
            ],
            'hour-only shorthand' => [
                [['label' => 'Sob', 'hours' => '7-17']],
                [['dayOfWeek' => 'https://schema.org/Saturday', 'opens' => '07:00', 'closes' => '17:00']],
            ],
            'full Polish day name with trailing dot' => [
                [['label' => 'Sobota.', 'hours' => '8:00-14:00']],
                [['dayOfWeek' => 'https://schema.org/Saturday', 'opens' => '08:00', 'closes' => '14:00']],
            ],
            'diacritic-free day name' => [
                [['label' => 'Sroda', 'hours' => '9:00-15:00']],
                [['dayOfWeek' => 'https://schema.org/Wednesday', 'opens' => '09:00', 'closes' => '15:00']],
            ],
            'single Sunday abbreviation, em dash range' => [
                [['label' => 'Niedz', 'hours' => '10:00—14:00']],
                [['dayOfWeek' => 'https://schema.org/Sunday', 'opens' => '10:00', 'closes' => '14:00']],
            ],
        ];
    }

    #[DataProvider('unambiguousEntryProvider')]
    public function test_it_emits_specifications_for_unambiguous_entries(array $entries, array $expected): void
    {
        $this->assertSame($expected, array_map(
            fn (array $spec) => [
                'dayOfWeek' => $spec['dayOfWeek'],
                'opens' => $spec['opens'],
                'closes' => $spec['closes'],
            ],
            OpeningHoursParser::parseSpecifications($entries)
        ));
    }

    public static function ambiguousEntryProvider(): array
    {
        return [
            'closed, not a time range' => [[['label' => 'Sob', 'hours' => 'Zamknięte']]],
            'phone-only hours' => [[['label' => 'Pon', 'hours' => 'na telefon']]],
            'vague day label' => [[['label' => 'Dni robocze', 'hours' => '7:00-17:00']]],
            'whole-week label' => [[['label' => 'Cały tydzień', 'hours' => '7:00-17:00']]],
            'weekend label has no literal day token' => [[['label' => 'Weekend', 'hours' => '9:00-13:00']]],
            'wrap-around range not interpreted' => [[['label' => 'Pt-Pon', 'hours' => '7:00-17:00']]],
            'day case ending not matched (grammatical, not a token)' => [[['label' => 'Poniedziałek do piątku', 'hours' => '7:00-17:00']]],
            'invalid hour value' => [[['label' => 'Pon', 'hours' => '25:00-30:00']]],
            'empty hours' => [[['label' => 'Pon', 'hours' => '']]],
            'empty label' => [[['label' => '', 'hours' => '7:00-17:00']]],
            'non-array entry is skipped, not fatal' => [['not-an-array']],
        ];
    }

    #[DataProvider('ambiguousEntryProvider')]
    public function test_it_omits_specifications_for_ambiguous_entries(array $entries): void
    {
        $this->assertSame([], OpeningHoursParser::parseSpecifications($entries));
    }

    public function test_ambiguous_entries_do_not_suppress_unambiguous_siblings(): void
    {
        $specs = OpeningHoursParser::parseSpecifications([
            ['label' => 'Dni robocze', 'hours' => '7:00-17:00'],
            ['label' => 'Sob', 'hours' => '8:00-13:00'],
            ['label' => 'Niedz', 'hours' => 'Zamknięte'],
        ]);

        $this->assertSame([
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Saturday', 'opens' => '08:00', 'closes' => '13:00'],
        ], $specs);
    }

    public function test_is_unambiguous_time_range_matches_the_same_grammar(): void
    {
        $this->assertTrue(OpeningHoursParser::isUnambiguousTimeRange('7:00–17:00'));
        $this->assertTrue(OpeningHoursParser::isUnambiguousTimeRange('07:00 - 19:00'));
        $this->assertTrue(OpeningHoursParser::isUnambiguousTimeRange('7-17'));
        $this->assertFalse(OpeningHoursParser::isUnambiguousTimeRange('Zamknięte'));
        $this->assertFalse(OpeningHoursParser::isUnambiguousTimeRange('na telefon'));
        $this->assertFalse(OpeningHoursParser::isUnambiguousTimeRange(''));
    }
}
