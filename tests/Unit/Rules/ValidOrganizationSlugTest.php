<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidOrganizationSlug;
use PHPUnit\Framework\TestCase;

class ValidOrganizationSlugTest extends TestCase
{
    private ValidOrganizationSlug $rule;

    private array $errors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ValidOrganizationSlug;
        $this->errors = [];
    }

    private function validate(string $value): bool
    {
        $this->errors = [];
        $failed = false;

        $this->rule->validate('slug', $value, function (string $message) use (&$failed) {
            $this->errors[] = $message;
            $failed = true;
        });

        return ! $failed;
    }

    public function test_accepts_valid_slugs(): void
    {
        $this->assertTrue($this->validate('my-salon'));
        $this->assertTrue($this->validate('salon123'));
        $this->assertTrue($this->validate('a1b'));
        $this->assertTrue($this->validate('abc'));
        $this->assertTrue($this->validate('bella-studio'));
    }

    public function test_rejects_too_short_slugs(): void
    {
        $this->assertFalse($this->validate('ab'));
        $this->assertFalse($this->validate('a'));
    }

    public function test_rejects_too_long_slugs(): void
    {
        $this->assertFalse($this->validate(str_repeat('a', 64)));
    }

    public function test_normalizes_uppercase_to_lowercase(): void
    {
        // Rule uses strtolower() — uppercase input is normalized and accepted
        $this->assertTrue($this->validate('MySlug'));
        $this->assertTrue($this->validate('SALON'));
    }

    public function test_rejects_starting_with_hyphen(): void
    {
        $this->assertFalse($this->validate('-slug'));
    }

    public function test_rejects_ending_with_hyphen(): void
    {
        $this->assertFalse($this->validate('slug-'));
    }

    public function test_rejects_double_hyphens(): void
    {
        $this->assertFalse($this->validate('my--slug'));
    }

    public function test_rejects_reserved_slugs(): void
    {
        $reserved = ['www', 'api', 'admin', 'platform', 'mail', 'staging', 'registro'];

        foreach ($reserved as $slug) {
            $this->assertFalse($this->validate($slug), "Should reject reserved slug: {$slug}");
        }
    }

    public function test_rejects_special_characters(): void
    {
        $this->assertFalse($this->validate('my_slug'));
        $this->assertFalse($this->validate('my.slug'));
        $this->assertFalse($this->validate('my slug'));
    }
}
