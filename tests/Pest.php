<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Scoped ONLY to tests/Browser. The other 123 test files in this project
| are classical PHPUnit test classes (extends Tests\TestCase) — they are
| not "Pest tests" and this file must never change their behavior.
|
| Browser tests are Pest's functional style and run through the built-in
| Playwright-driven server (same PHP process — see LaravelHttpServer),
| so RefreshDatabase + the seeders in Tests\TestCase::setUp() work exactly
| like any other Feature test.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Browser');
