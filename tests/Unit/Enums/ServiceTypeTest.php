<?php

namespace Tests\Unit\Enums;

use App\Enums\ServiceType;
use PHPUnit\Framework\TestCase;

class ServiceTypeTest extends TestCase
{
    public function test_time_slot_label(): void
    {
        $this->assertEquals('Usługa (rezerwacja terminu)', ServiceType::TimeSlot->label());
    }

    public function test_item_rental_label(): void
    {
        $this->assertEquals('Wypożyczenie (przedmiot)', ServiceType::ItemRental->label());
    }

    public function test_time_slot_icon(): void
    {
        $this->assertEquals('heroicon-o-calendar', ServiceType::TimeSlot->icon());
    }

    public function test_item_rental_icon(): void
    {
        $this->assertEquals('heroicon-o-cube', ServiceType::ItemRental->icon());
    }

    public function test_time_slot_value(): void
    {
        $this->assertEquals('time_slot', ServiceType::TimeSlot->value);
    }

    public function test_item_rental_value(): void
    {
        $this->assertEquals('item_rental', ServiceType::ItemRental->value);
    }

    public function test_try_from_valid_values(): void
    {
        $this->assertEquals(ServiceType::TimeSlot, ServiceType::tryFrom('time_slot'));
        $this->assertEquals(ServiceType::ItemRental, ServiceType::tryFrom('item_rental'));
    }

    public function test_try_from_invalid_value(): void
    {
        $this->assertNull(ServiceType::tryFrom('invalid'));
    }
}
