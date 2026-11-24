<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * `RefreshDatabase::migrateFreshUsing()` reads this and passes
     * `--seeder=...` to `migrate:fresh`, which runs it exactly ONCE per test
     * process (before the first test's transaction begins) instead of once
     * per test. Tests that use RefreshDatabase get roles/permissions, email
     * templates, and vehicle types seeded automatically; tests that don't
     * use RefreshDatabase never trigger `migrate:fresh` at all, so this
     * property is simply unused for them. See
     * `database/seeders/TestReferenceDataSeeder.php` and
     * `.claude/rules/tests.md` -> "Reference data seeding".
     */
    protected $seeder = \Database\Seeders\TestReferenceDataSeeder::class;

    /**
     * Sorts the keys of every associative array, however deep, and leaves LISTS in the order they came.
     *
     * Why: MySQL stores a JSON column in its binary JSON type, which re-orders object keys (shorter key
     * first, then bytewise: `unit, label, value` for a row written as `label, value, unit`) and puts a
     * space after `:` and `,` when it prints the document back. SQLite keeps the text it was given. So a
     * `assertSame()` on an associative array read back from a JSON column passes on SQLite and fails on
     * MySQL (PR #316, first CI run) although nothing is wrong: key order inside an object carries no
     * meaning, while the order of the items of a list does (spec rows, content blocks), and this keeps
     * that distinction. Types are still compared strictly afterwards, so `null` vs `''` vs `0` stay
     * different - which `assertEquals()` would NOT keep.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function ksortDeep(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->ksortDeep($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * `assertSame()` that ignores the key order inside objects of a JSON column (see ksortDeep()) but
     * still checks list order and every value's type. Use it for anything read back from a JSON / array
     * cast column, never for a raw JSON string (decode that first: MySQL re-formats it too).
     *
     * @param  array<mixed>  $expected
     */
    protected function assertSameJson(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertSame($this->ksortDeep($expected), $this->ksortDeep($actual), $message);
    }
}
