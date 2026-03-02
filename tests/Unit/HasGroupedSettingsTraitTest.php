<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Traits\HasGroupedSettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HasGroupedSettings trait.
 *
 * Tests the normalizeFileUploadValue() method which handles:
 * - Simple Repeater: ['uuid' => ['item' => 'text']] → ['text1', 'text2']
 * - Complex Repeater: ['uuid' => ['name' => 'X', 'icon' => 'Y']] → keep structure
 * - FileUpload: ['uuid' => 'path/to/file.jpg'] → 'path/to/file.jpg'
 *
 * @see app/Filament/Traits/HasGroupedSettings.php
 */
class HasGroupedSettingsTraitTest extends TestCase
{
    /**
     * Get a mock object that uses the trait.
     */
    private function getTraitMock(): object
    {
        return new class
        {
            use HasGroupedSettings;

            protected function getSettingsGroups(): array
            {
                return [];
            }

            /**
             * Expose private method for testing.
             */
            public function testNormalize(mixed $value): mixed
            {
                return $this->normalizeFileUploadValue($value);
            }
        };
    }

    /**
     * Test Simple Repeater with UUID keys is flattened.
     *
     * Input: ['uuid-abc' => ['item' => 'Text 1'], 'uuid-def' => ['item' => 'Text 2']]
     * Output: ['Text 1', 'Text 2']
     */
    public function test_simple_repeater_with_uuid_keys_flattens_to_array(): void
    {
        $mock = $this->getTraitMock();

        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => ['item' => 'Text 1'],
            'b2c3d4e5-f6a7-8901-bcde-f12345678901' => ['item' => 'Text 2'],
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals(['Text 1', 'Text 2'], $result);
    }

    /**
     * Test Complex Repeater preserves structure.
     *
     * Input: ['uuid' => ['name' => 'X', 'icon' => 'Y'], ...]
     * Output: [['name' => 'X', 'icon' => 'Y'], ...]
     */
    public function test_complex_repeater_preserves_structure(): void
    {
        $mock = $this->getTraitMock();

        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => ['name' => 'Type 1', 'icon' => 'sun'],
            'b2c3d4e5-f6a7-8901-bcde-f12345678901' => ['name' => 'Type 2', 'icon' => 'moon'],
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals([
            ['name' => 'Type 1', 'icon' => 'sun'],
            ['name' => 'Type 2', 'icon' => 'moon'],
        ], $result);
    }

    /**
     * Test FileUpload with path string is extracted.
     *
     * Input: ['uuid' => 'settings/logos/logo.svg']
     * Output: 'settings/logos/logo.svg'
     */
    public function test_file_upload_extracts_path_string(): void
    {
        $mock = $this->getTraitMock();

        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => 'settings/logos/logo.svg',
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals('settings/logos/logo.svg', $result);
    }

    /**
     * Test already-processed numeric array passes through.
     */
    public function test_numeric_array_passes_through(): void
    {
        $mock = $this->getTraitMock();

        $input = ['Item 1', 'Item 2', 'Item 3'];

        $result = $mock->testNormalize($input);

        $this->assertEquals(['Item 1', 'Item 2', 'Item 3'], $result);
    }

    /**
     * Test empty array returns null.
     */
    public function test_empty_array_returns_null(): void
    {
        $mock = $this->getTraitMock();

        $result = $mock->testNormalize([]);

        $this->assertNull($result);
    }

    /**
     * Test non-array value passes through.
     */
    public function test_non_array_value_passes_through(): void
    {
        $mock = $this->getTraitMock();

        $this->assertEquals('string value', $mock->testNormalize('string value'));
        $this->assertEquals(123, $mock->testNormalize(123));
        $this->assertTrue($mock->testNormalize(true));
        $this->assertNull($mock->testNormalize(null));
    }

    /**
     * Test single-item Simple Repeater flattens correctly.
     */
    public function test_single_item_simple_repeater(): void
    {
        $mock = $this->getTraitMock();

        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => ['item' => 'Only Item'],
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals(['Only Item'], $result);
    }

    /**
     * Test mixed Repeater (some items with 1 key, some with 2+) treats as Complex.
     *
     * This edge case shouldn't happen in practice but tests defensive coding.
     */
    public function test_mixed_key_count_treats_as_complex(): void
    {
        $mock = $this->getTraitMock();

        // First item has 1 key, second has 2 - should treat as Complex
        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => ['name' => 'Only name'],
            'b2c3d4e5-f6a7-8901-bcde-f12345678901' => ['name' => 'Name', 'icon' => 'star'],
        ];

        $result = $mock->testNormalize($input);

        // Since not ALL items have exactly 1 key, keep structure
        $this->assertEquals([
            ['name' => 'Only name'],
            ['name' => 'Name', 'icon' => 'star'],
        ], $result);
    }

    /**
     * Test Repeater with different field names still works.
     *
     * Simple Repeater can use any field name, not just 'item'.
     */
    public function test_simple_repeater_with_custom_field_name(): void
    {
        $mock = $this->getTraitMock();

        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => ['text' => 'First'],
            'b2c3d4e5-f6a7-8901-bcde-f12345678901' => ['text' => 'Second'],
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals(['First', 'Second'], $result);
    }

    /**
     * Test FileUpload with non-settings path still extracts.
     */
    public function test_file_upload_with_various_paths(): void
    {
        $mock = $this->getTraitMock();

        // settings/ prefix
        $input1 = ['a1b2c3d4-e5f6-7890-abcd-ef1234567890' => 'settings/logos/test.svg'];
        $this->assertEquals('settings/logos/test.svg', $mock->testNormalize($input1));

        // Other path with extension
        $input2 = ['a1b2c3d4-e5f6-7890-abcd-ef1234567890' => 'uploads/images/photo.jpg'];
        $this->assertEquals('uploads/images/photo.jpg', $mock->testNormalize($input2));
    }

    /**
     * Test string without path characteristics is treated as Simple Repeater.
     */
    public function test_string_values_without_path_are_simple_repeater(): void
    {
        $mock = $this->getTraitMock();

        // Multiple strings without file path characteristics
        $input = [
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890' => 'Just text',
            'b2c3d4e5-f6a7-8901-bcde-f12345678901' => 'More text',
        ];

        $result = $mock->testNormalize($input);

        $this->assertEquals(['Just text', 'More text'], $result);
    }
}
