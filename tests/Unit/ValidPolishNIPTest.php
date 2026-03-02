<?php

namespace Tests\Unit;

use App\Rules\ValidPolishNIP;
use PHPUnit\Framework\TestCase;

class ValidPolishNIPTest extends TestCase
{
    /**
     * Test that valid NIP passes validation.
     */
    public function test_validates_correct_nip(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '7751001452', function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, 'Valid NIP 7751001452 should pass validation');
    }

    /**
     * Test that NIP with dashes is accepted and validated correctly.
     */
    public function test_accepts_nip_with_dashes(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '775-100-14-52', function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, 'Valid NIP with dashes 775-100-14-52 should pass validation');
    }

    /**
     * Test that NIP with spaces is accepted and validated correctly.
     */
    public function test_accepts_nip_with_spaces(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '775 100 14 52', function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, 'Valid NIP with spaces should pass validation');
    }

    /**
     * Test that incorrect checksum fails validation.
     */
    public function test_rejects_incorrect_checksum(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '7751001455', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'NIP with incorrect checksum should fail validation');
    }

    /**
     * Test that checksum of 10 fails validation (invalid by algorithm).
     * This is the CRITICAL bug fix - NIP with checksum 10 has no valid control digit.
     */
    public function test_rejects_checksum_of_ten(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        // NIP 1234567890 has checksum calculation that results in 10
        $rule->validate('nip', '1234567890', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'NIP with checksum of 10 should fail validation');
    }

    /**
     * Test that NIP with wrong length fails validation.
     */
    public function test_rejects_nip_with_wrong_length(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '123456789', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'NIP with 9 digits should fail validation');
    }

    /**
     * Test that NIP with too many digits fails validation.
     */
    public function test_rejects_nip_with_too_many_digits(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '12345678901', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'NIP with 11 digits should fail validation');
    }

    /**
     * Test that NIP with non-digit characters fails validation.
     */
    public function test_rejects_nip_with_letters(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '775100145A', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'NIP with letters should fail validation');
    }

    /**
     * Test that empty NIP fails validation (NIP is now required).
     */
    public function test_rejects_empty_nip(): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '', function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, 'Empty NIP should fail validation');
    }

    /**
     * Test multiple valid NIP examples.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validNIPProvider')]
    public function test_validates_multiple_valid_nips(string $nip): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', $nip, function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, "Valid NIP {$nip} should pass validation");
    }

    /**
     * Provide valid NIP examples for testing.
     */
    public static function validNIPProvider(): array
    {
        return [
            'NIP without formatting' => ['7751001452'],
            'NIP with dashes' => ['775-100-14-52'],
            'Another valid NIP' => ['1234563218'],
            'NIP with different checksum' => ['5252828563'],
        ];
    }

    /**
     * Test multiple invalid NIP examples.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNIPProvider')]
    public function test_rejects_multiple_invalid_nips(string $nip): void
    {
        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', $nip, function () use (&$passes) {
            $passes = false;
        });

        $this->assertFalse($passes, "Invalid NIP {$nip} should fail validation");
    }

    /**
     * Provide invalid NIP examples for testing.
     */
    public static function invalidNIPProvider(): array
    {
        return [
            'Incorrect checksum (should be 2)' => ['7751001454'],
            'Incorrect checksum (should be 3)' => ['5252828562'],
            'Too short' => ['123456789'],
            'Too long' => ['12345678901'],
            'With letters' => ['775100145A'],
            'Checksum of 10 (invalid)' => ['1234567890'],
        ];
    }

    /**
     * Test the actual checksum calculation for a known valid NIP.
     */
    public function test_checksum_calculation(): void
    {
        // NIP: 7751001452 (valid NIP)
        // Calculation: (7×6 + 7×5 + 5×7 + 1×2 + 0×3 + 0×4 + 1×5 + 4×6 + 5×7) mod 11
        // = (42 + 35 + 35 + 2 + 0 + 0 + 5 + 24 + 35) mod 11
        // = 178 mod 11
        // = 2 ✓ (matches digit[9])

        $rule = new ValidPolishNIP;
        $passes = true;

        $rule->validate('nip', '7751001452', function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, 'Correctly calculated NIP should pass validation');
    }
}
