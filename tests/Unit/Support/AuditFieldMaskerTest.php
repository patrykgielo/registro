<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Audit\AuditFieldMasker;
use PHPUnit\Framework\TestCase;

class AuditFieldMaskerTest extends TestCase
{
    public function test_masks_pesel_keeping_last_four_digits(): void
    {
        $masked = AuditFieldMasker::mask(['customer_pesel' => '12345678901']);

        $this->assertSame('*******8901', $masked['customer_pesel']);
    }

    public function test_masks_id_document_number_fields(): void
    {
        $masked = AuditFieldMasker::mask([
            'signatory_id_number' => 'ABC123456',
            'pickup_person_id_number' => 'XYZ987654',
        ]);

        $this->assertSame('*****3456', $masked['signatory_id_number']);
        $this->assertSame('*****7654', $masked['pickup_person_id_number']);
    }

    public function test_leaves_unrelated_fields_untouched(): void
    {
        $masked = AuditFieldMasker::mask([
            'customer_pesel' => '12345678901',
            'customer_city' => 'Warszawa',
            'status' => 'confirmed',
        ]);

        $this->assertSame('Warszawa', $masked['customer_city']);
        $this->assertSame('confirmed', $masked['status']);
    }

    public function test_null_values_and_null_input_are_safe(): void
    {
        $this->assertNull(AuditFieldMasker::mask(null));

        $masked = AuditFieldMasker::mask(['customer_pesel' => null]);
        $this->assertNull($masked['customer_pesel']);
    }

    public function test_short_values_are_fully_masked(): void
    {
        $masked = AuditFieldMasker::mask(['pesel' => '12']);

        $this->assertSame('**', $masked['pesel']);
    }
}
