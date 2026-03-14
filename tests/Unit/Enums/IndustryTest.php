<?php

namespace Tests\Unit\Enums;

use App\Actions\Onboarding\Seeders\SeedAutoDetailing;
use App\Actions\Onboarding\Seeders\SeedEquipmentRental;
use App\Actions\Onboarding\Seeders\SeedGeneralServices;
use App\Enums\Industry;
use PHPUnit\Framework\TestCase;

class IndustryTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        $cases = Industry::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(Industry::EquipmentRental, $cases);
        $this->assertContains(Industry::AutoDetailing, $cases);
        $this->assertContains(Industry::GeneralServices, $cases);
    }

    public function test_values(): void
    {
        $this->assertEquals('equipment_rental', Industry::EquipmentRental->value);
        $this->assertEquals('auto_detailing', Industry::AutoDetailing->value);
        $this->assertEquals('general_services', Industry::GeneralServices->value);
    }

    public function test_labels(): void
    {
        $this->assertEquals('Wypożyczalnia sprzętu', Industry::EquipmentRental->label());
        $this->assertEquals('Auto detailing', Industry::AutoDetailing->label());
        $this->assertEquals('Inna działalność', Industry::GeneralServices->label());
    }

    public function test_booking_types(): void
    {
        $this->assertEquals('item_rental', Industry::EquipmentRental->bookingType());
        $this->assertEquals('time_slot', Industry::AutoDetailing->bookingType());
        $this->assertEquals('time_slot', Industry::GeneralServices->bookingType());
    }

    public function test_equipment_rental_features(): void
    {
        $features = Industry::EquipmentRental->defaultFeatures();

        $this->assertFalse($features['vehicles']);
        $this->assertFalse($features['mobile_service']);
        $this->assertFalse($features['service_area']);
    }

    public function test_auto_detailing_features(): void
    {
        $features = Industry::AutoDetailing->defaultFeatures();

        $this->assertTrue($features['vehicles']);
        $this->assertTrue($features['mobile_service']);
        $this->assertTrue($features['service_area']);
    }

    public function test_general_services_features(): void
    {
        $features = Industry::GeneralServices->defaultFeatures();

        $this->assertFalse($features['vehicles']);
        $this->assertFalse($features['mobile_service']);
        $this->assertFalse($features['service_area']);
    }

    public function test_terminology(): void
    {
        $rental = Industry::EquipmentRental->terminology();
        $this->assertEquals('przedmiot', $rental['service']);
        $this->assertEquals('wypożyczenie', $rental['booking']);
        $this->assertEquals('wypożyczający', $rental['customer']);

        $detailing = Industry::AutoDetailing->terminology();
        $this->assertEquals('usługa', $detailing['service']);
        $this->assertEquals('rezerwacja', $detailing['booking']);
        $this->assertEquals('klient', $detailing['customer']);
    }

    public function test_seeder_classes(): void
    {
        $this->assertEquals(SeedEquipmentRental::class, Industry::EquipmentRental->seederClass());
        $this->assertEquals(SeedAutoDetailing::class, Industry::AutoDetailing->seederClass());
        $this->assertEquals(SeedGeneralServices::class, Industry::GeneralServices->seederClass());
    }

    public function test_icons(): void
    {
        $this->assertNotEmpty(Industry::EquipmentRental->icon());
        $this->assertNotEmpty(Industry::AutoDetailing->icon());
        $this->assertNotEmpty(Industry::GeneralServices->icon());
    }

    public function test_descriptions(): void
    {
        $this->assertNotEmpty(Industry::EquipmentRental->description());
        $this->assertNotEmpty(Industry::AutoDetailing->description());
        $this->assertNotEmpty(Industry::GeneralServices->description());
    }

    public function test_from_value(): void
    {
        $this->assertEquals(Industry::EquipmentRental, Industry::from('equipment_rental'));
        $this->assertEquals(Industry::AutoDetailing, Industry::from('auto_detailing'));
        $this->assertEquals(Industry::GeneralServices, Industry::from('general_services'));
    }
}
