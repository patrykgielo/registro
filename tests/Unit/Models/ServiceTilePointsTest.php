<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ServiceType;
use App\Models\Service;
use Tests\TestCase;

/**
 * `Service::tilePoints()` - the "✓" points on the product tile: the spec rows the owner starred
 * (`metadata.specs[].highlight`, rentals only) as "Label: value unit", then `features`, 4 in all.
 * Pure model logic: nothing is saved.
 */
class ServiceTilePointsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function service(ServiceType $type, array $attributes = []): Service
    {
        return (new Service)->forceFill(['service_type' => $type] + $attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $specs
     * @param  array<int, mixed>|null  $features
     */
    private function rental(array $specs, ?array $features = null): Service
    {
        return $this->service(ServiceType::ItemRental, ['metadata' => ['specs' => $specs], 'features' => $features]);
    }

    public function test_starred_rows_come_first_as_label_value_unit_then_the_features(): void
    {
        $rental = $this->rental([
            ['label' => 'Moc', 'value' => '800', 'unit' => 'W', 'highlight' => true],
            ['label' => 'Waga', 'value' => '2,4', 'unit' => 'kg'],
            ['label' => 'Napięcie', 'value' => '230', 'unit' => 'V', 'highlight' => true],
        ], ['Walizka w komplecie', 'Cicha praca']);

        $this->assertSame(['Moc: 800 W', 'Napięcie: 230 V', 'Walizka w komplecie', 'Cicha praca'], $rental->tilePoints());
    }

    public function test_the_unit_is_left_out_when_it_is_empty_or_missing(): void
    {
        $rental = $this->rental([
            ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null, 'highlight' => true],
            ['label' => 'Klasa', 'value' => 'A', 'unit' => '', 'highlight' => true],
            ['label' => 'Gwarancja', 'value' => '2 lata', 'highlight' => true],
        ]);

        $this->assertSame(['Rodzaj paliwa: benzyna', 'Klasa: A', 'Gwarancja: 2 lata'], $rental->tilePoints());
    }

    public function test_the_value_is_printed_as_stored_text_or_number(): void
    {
        $rental = $this->rental([
            ['label' => 'Moc', 'value' => '2,2 kVA (2200 W)', 'unit' => null, 'highlight' => true],
            ['label' => 'Ciśnienie', 'value' => 21, 'unit' => 'bar', 'highlight' => true],
            ['label' => 'Obroty', 'value' => 0, 'unit' => 'obr/min', 'highlight' => true],
        ]);

        $this->assertSame(['Moc: 2,2 kVA (2200 W)', 'Ciśnienie: 21 bar', 'Obroty: 0 obr/min'], $rental->tilePoints());
    }

    public function test_the_tile_shows_four_points_in_all(): void
    {
        $rental = $this->rental([
            ['label' => 'A', 'value' => '1', 'unit' => null, 'highlight' => true],
            ['label' => 'B', 'value' => '2', 'unit' => null, 'highlight' => true],
            ['label' => 'C', 'value' => '3', 'unit' => null, 'highlight' => true],
        ], ['f1', 'f2', 'f3']);

        $this->assertSame(['A: 1', 'B: 2', 'C: 3', 'f1'], $rental->tilePoints(), 'features only fill what the stars left');
        $this->assertSame(4, Service::TILE_POINTS_LIMIT);
    }

    public function test_more_than_four_starred_rows_are_cut_to_the_first_four(): void
    {
        $rows = [];
        foreach (range(1, 6) as $n) {
            $rows[] = ['label' => "P{$n}", 'value' => (string) $n, 'unit' => null, 'highlight' => true];
        }

        $this->assertSame(['P1: 1', 'P2: 2', 'P3: 3', 'P4: 4'], $this->rental($rows, ['f'])->tilePoints());
    }

    public function test_blank_features_are_skipped_and_do_not_use_up_a_slot(): void
    {
        $rental = $this->rental([], ['', '   ', 'Pierwsza', null, 'Druga', 'Trzecia', 'Czwarta', 'Piąta']);

        $this->assertSame(['Pierwsza', 'Druga', 'Trzecia', 'Czwarta'], $rental->tilePoints());
    }

    public function test_a_rental_with_nothing_starred_shows_only_its_features_like_before(): void
    {
        $rental = $this->rental([
            ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
            ['label' => 'Waga', 'value' => '2,4', 'unit' => 'kg', 'highlight' => false],
        ], ['Walizka', 'Ładowarka']);

        $this->assertSame(['Walizka', 'Ładowarka'], $rental->tilePoints());
    }

    public function test_only_a_real_true_counts_as_starred(): void
    {
        $rental = $this->rental([
            ['label' => 'A', 'value' => '1', 'unit' => null, 'highlight' => 'true'],
            ['label' => 'B', 'value' => '2', 'unit' => null, 'highlight' => 1],
            ['label' => 'C', 'value' => '3', 'unit' => null, 'highlight' => null],
        ]);

        $this->assertSame([], $rental->tilePoints(), 'the model normalises on save, so anything but `true` is not a star');
    }

    public function test_a_starred_row_without_a_label_or_a_value_has_nothing_to_show_and_is_skipped(): void
    {
        $rental = $this->rental([
            ['label' => 'Moc', 'value' => '', 'unit' => 'W', 'highlight' => true],
            ['label' => '', 'value' => '800', 'unit' => 'W', 'highlight' => true],
            ['label' => null, 'value' => null, 'unit' => null, 'highlight' => true],
            ['label' => 'Waga', 'value' => '2', 'unit' => 'kg', 'highlight' => true],
        ]);

        $this->assertSame(['Waga: 2 kg'], $rental->tilePoints());
    }

    public function test_a_time_slot_ignores_specs_even_if_a_row_is_starred(): void
    {
        $slot = $this->service(ServiceType::TimeSlot, [
            'metadata' => ['specs' => [['label' => 'Moc', 'value' => '800', 'unit' => 'W', 'highlight' => true]]],
            'features' => ['Mycie', 'Woskowanie'],
        ]);

        $this->assertSame(['Mycie', 'Woskowanie'], $slot->tilePoints());
    }

    public function test_missing_or_malformed_data_gives_an_empty_list_instead_of_an_error(): void
    {
        $this->assertSame([], $this->service(ServiceType::ItemRental)->tilePoints());
        $this->assertSame([], $this->service(ServiceType::TimeSlot, ['features' => null])->tilePoints());
        $this->assertSame([], $this->service(ServiceType::ItemRental, ['metadata' => ['specs' => 'x'], 'features' => 'y'])->tilePoints());
        $this->assertSame(
            ['Dobra'],
            $this->service(ServiceType::ItemRental, ['metadata' => ['specs' => ['nie-wiersz', ['highlight' => true]]], 'features' => [['tablica'], 'Dobra']])->tilePoints(),
        );
    }

    public function test_the_model_hook_keeps_a_true_highlight_and_drops_every_other_value(): void
    {
        $normalised = Service::normalizeSpecHighlights([
            ['label' => 'A', 'value' => '1', 'unit' => null, 'highlight' => true],
            ['label' => 'B', 'value' => '2', 'unit' => null, 'highlight' => false],
            ['label' => 'C', 'value' => '3', 'unit' => null, 'highlight' => null],
            ['label' => 'D', 'value' => '4', 'unit' => null, 'highlight' => '1'],
            ['label' => 'E', 'value' => '5', 'unit' => null],
        ]);

        $this->assertSame([
            ['label' => 'A', 'value' => '1', 'unit' => null, 'highlight' => true],
            ['label' => 'B', 'value' => '2', 'unit' => null],
            ['label' => 'C', 'value' => '3', 'unit' => null],
            ['label' => 'D', 'value' => '4', 'unit' => null, 'highlight' => true],
            ['label' => 'E', 'value' => '5', 'unit' => null],
        ], $normalised);
    }
}
