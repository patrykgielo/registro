<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ImportCatalog;
use App\Actions\Catalog\ImportOptions;
use App\Models\CatalogImportBatch;
use App\Models\Organization;
use App\Models\Service;
use App\Support\Catalog\CatalogDocument;
use App\Support\Catalog\CatalogProductValidator;
use App\Support\Catalog\ImageInspector;
use App\Support\Catalog\SafeImageFetcher;
use App\Support\Catalog\ValidationReport;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Second review round: purifier profile, organization argument, hostile strings on the console,
 * document path, post-commit failures on the import side, image-phase budget, stale scratch.
 */
class CatalogImportHardeningTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
    }

    private function services(Organization $org)
    {
        return Service::withoutGlobalScope('organization')->where('organization_id', $org->id);
    }

    private function import(Organization $org, string $file, array $flags = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('catalog:import', ['organization' => (string) $org->id, 'file' => $file, '--image-delay' => '0'] + $flags);
    }

    /** @return array{0: int, 1: string} exit code and output of a real, non-decorated Artisan run */
    private function cli(string $command, array $args): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function bindImport(ImportCatalog $action): void
    {
        $this->app->bind(ImportCatalog::class, fn () => $action);
    }

    // ================================================================== 6. purifier profile

    public function test_body_html_is_purified_with_the_importer_only_profile(): void
    {
        $org = $this->rentalTenant();
        $html = '<p style="color:red">Akapit <strong>gruby</strong> <em>kursywa</em> <u>podkreślony</u> <a href="https://example.com" title="t" onclick="x()">link</a></p>'
            .'<img src="https://tracker.example/p.gif" width="1" height="1"><div>blok</div><span style="color:red">span</span>'
            .'<h2>Dwa</h2><h3>Trzy</h3><h4>Cztery</h4><h5>Pięć</h5><ul><li>a</li></ul><ol><li>b</li></ol><blockquote>cytat</blockquote><script>alert(1)</script>';

        $this->import($org, $this->catalogFile([$this->product(['body_html' => $html, 'featured_image' => null, 'gallery' => []])]))->assertExitCode(0);

        $body = (string) $this->services($org)->firstOrFail()->body;

        foreach (['<strong>gruby</strong>', '<em>kursywa</em>', '<u>podkreślony</u>', '<a href="https://example.com" title="t">', '<h2>Dwa</h2>', '<h3>Trzy</h3>', '<ul>', '<ol>', '<li>a</li>', '<blockquote>'] as $kept) {
            $this->assertStringContainsString($kept, $body);
        }
        foreach (['<img', 'tracker.example', '<div', '<span', 'style=', '<h4', '<h5', '<script', 'onclick', 'alert(1)'] as $stripped) {
            $this->assertStringNotContainsString($stripped, $body, "{$stripped} must not survive the importer profile");
        }
    }

    public function test_the_byte_cap_is_measured_on_the_importer_profile_output(): void
    {
        $org = $this->rentalTenant();
        // an <img alt> of 70 kB survives the DEFAULT profile (img[alt] allowed) but not the importer's
        $html = '<img alt="'.str_repeat('a', 70000).'" src="https://example.com/x.png"><p>treść</p>';

        $report = (new CatalogProductValidator)->validate([$this->product(['body_html' => $html])], $org);

        $this->assertFalse($report->hasErrors(), json_encode($report->errors()));
    }

    // ================================================================== 8. organization argument

    private function orgWithId(int $id, array $attrs = []): Organization
    {
        $org = Organization::factory()->equipmentRental()->make($attrs);
        $org->id = $id;
        $org->save();

        return $org;
    }

    public function test_a_bare_number_that_is_both_an_id_and_a_slug_is_ambiguous(): void
    {
        $byId = $this->orgWithId(77, ['name' => 'Firma Id77', 'slug' => 'id-77']);
        $bySlug = $this->orgWithId(78, ['name' => 'Firma Slug77', 'slug' => '77']);

        [$code, $output] = $this->cli('catalog:import', ['organization' => '77', 'file' => $this->catalogFile([$this->product()]), '--dry-run' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('niejednoznaczn', $output);
        $this->assertStringContainsString('id:77', $output);
        $this->assertStringContainsString('slug:77', $output);
        $this->assertSame(0, $this->services($byId)->count() + $this->services($bySlug)->count());
    }

    public function test_explicit_prefixes_pick_the_organization_and_it_is_printed_before_anything_else(): void
    {
        $byId = $this->orgWithId(77, ['name' => 'Firma Id77', 'slug' => 'id-77']);
        $bySlug = $this->orgWithId(78, ['name' => 'Firma Slug77', 'slug' => '77']);
        foreach ([$byId, $bySlug] as $org) {
            \App\Models\Location::factory()->for($org, 'organization')->create(['slug' => 'siedziba']);
        }
        $file = $this->catalogFile([$this->product()]);

        [, $out1] = $this->cli('catalog:import', ['organization' => 'id:77', 'file' => $file, '--dry-run' => true, '--no-image-check' => true]);
        [, $out2] = $this->cli('catalog:import', ['organization' => 'slug:77', 'file' => $file, '--dry-run' => true, '--no-image-check' => true]);

        $this->assertStringContainsString('Organizacja docelowa: Firma Id77 (id 77, slug id-77)', $out1);
        $this->assertStringContainsString('Organizacja docelowa: Firma Slug77 (id 78, slug 77)', $out2);
    }

    public function test_a_bare_number_resolves_to_whichever_one_exists(): void
    {
        $this->orgWithId(91, ['name' => 'Tylko id', 'slug' => 'tylko-id']);
        $this->orgWithId(92, ['name' => 'Tylko slug', 'slug' => '555']);

        [, $byId] = $this->cli('catalog:import', ['organization' => '91', 'file' => $this->catalogFile([$this->product()]), '--dry-run' => true, '--no-image-check' => true]);
        [, $bySlug] = $this->cli('catalog:import', ['organization' => '555', 'file' => $this->catalogFile([$this->product()]), '--dry-run' => true, '--no-image-check' => true]);

        $this->assertStringContainsString('Organizacja docelowa: Tylko id (id 91', $byId);
        $this->assertStringContainsString('Organizacja docelowa: Tylko slug (id 92', $bySlug);
    }

    public function test_the_target_is_printed_even_when_the_file_is_then_rejected(): void
    {
        $org = $this->rentalTenant();

        [$code, $output] = $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => '/no/such/file.json']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Organizacja docelowa: '.$org->name, $output);
    }

    public function test_unknown_prefixed_and_garbage_arguments_fail(): void
    {
        foreach (['id:999999', 'slug:nie-ma', 'id:abc', 'slug:', 'zzz'] as $arg) {
            [$code, $output] = $this->cli('catalog:import', ['organization' => $arg, 'file' => '/x.json']);
            $this->assertSame(1, $code, $arg);
            $this->assertStringContainsString('nie znaleziona', $output, $arg);
        }
    }

    public function test_the_status_command_uses_the_same_resolver(): void
    {
        $this->orgWithId(77, ['slug' => 'id-77']);
        $this->orgWithId(78, ['slug' => '77']);

        [$code, $output] = $this->cli('catalog:import-status', ['organization' => '77']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('niejednoznaczn', $output);

        [$code] = $this->cli('catalog:import-status', ['organization' => 'slug:77']);
        $this->assertSame(0, $code);
    }

    // ================================================================== 9. document path + console strings

    public function test_the_file_argument_rejects_stream_wrappers_and_non_files(): void
    {
        foreach (['phar:///tmp/x.phar/catalog.json', 'php://stdin', 'http://example.com/c.json', 'file:///etc/passwd', 'data://text/plain,{}'] as $bad) {
            try {
                CatalogDocument::load($bad);
                $this->fail("accepted {$bad}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('schemat', $e->getMessage(), $bad);
            }
        }

        $dir = sys_get_temp_dir().'/catalog-doc-'.bin2hex(random_bytes(3));
        mkdir($dir);
        $this->expectException(RuntimeException::class);
        CatalogDocument::load($dir); // a directory
    }

    public function test_hostile_strings_are_stripped_and_escaped_before_they_reach_the_console(): void
    {
        $report = new ValidationReport;
        $report->error(0, "ext\e[31m-1\x07", "field\x1b[2J<error>boom</error>", "msg \u{0085} <info>x</info> \e]0;title\x07");

        $row = $report->rows($report->errors())[0];
        $joined = implode('|', $row);

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $joined);
        $this->assertStringNotContainsString("\xC2\x85", $joined);
        $this->assertStringContainsString('\\<error\\>boom', $joined, 'formatter tags are escaped');
        $this->assertStringContainsString('\\<info\\>x', $joined);

        // end to end: a key chosen by the attacker lands in the error table — through the real formatter
        $org = $this->rentalTenant();
        $file = $this->catalogFile([$this->product(['external_id' => "<error>x</error>\e[31m", "<info>k\e[1m" => 1])]);

        [$code, $output] = $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $file, '--dry-run' => true, '--no-image-check' => true]);

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString("\e", $output);
        $this->assertStringContainsString('<error>x</error>', $output, 'the tag is printed literally, not interpreted');
    }

    // ================================================================== 1 (import side). post-commit failures

    private function importWithSeams(?Closure $afterCommit = null, ?Closure $forgetSitemap = null): ImportCatalog
    {
        return new class(app(CatalogProductValidator::class), $afterCommit, $forgetSitemap) extends ImportCatalog
        {
            public function __construct(CatalogProductValidator $v, private ?Closure $afterCommit, private ?Closure $forget)
            {
                parent::__construct($v);
            }

            protected function runWriteTransaction(Closure $callback): mixed
            {
                $result = parent::runWriteTransaction($callback);

                if ($this->afterCommit !== null) {
                    ($this->afterCommit)();
                }

                return $result;
            }

            protected function forgetSitemap(int $organizationId): void
            {
                if ($this->forget !== null) {
                    ($this->forget)();
                }

                parent::forgetSitemap($organizationId);
            }
        };
    }

    public function test_a_failing_sitemap_forget_after_commit_is_a_warning_not_a_failed_import(): void
    {
        $org = $this->rentalTenant();
        $this->bindImport($this->importWithSeams(forgetSitemap: fn () => throw new RuntimeException('cache down')));

        [$code, $output] = $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product()]), '--image-delay' => '0']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, CatalogImportBatch::count());
        $this->assertSame(1, $this->services($org)->count());
        $this->assertStringNotContainsString('nic nie zapisano', $output);
        $this->assertStringContainsString('mapa', mb_strtolower($output));
        $this->assertStringContainsString('catalog:import-status', $output);
    }

    public function test_an_exception_after_the_transaction_committed_keeps_the_files_and_says_so(): void
    {
        $org = $this->rentalTenant();
        $this->bindImport($this->importWithSeams(afterCommit: fn () => throw new RuntimeException('connection lost after commit')));

        [$code, $output] = $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product()]), '--image-delay' => '0']);

        $this->assertSame(1, $code);
        $this->assertSame(1, CatalogImportBatch::count(), 'the batch was committed');
        $this->assertNotEmpty(Storage::disk('public')->allFiles(), 'files of a COMMITTED batch must not be deleted');
        $this->assertStringNotContainsString('nic nie zapisano', $output);
        $this->assertStringContainsString('catalog:import-status', $output);
        $this->assertStringContainsString('zatwierdz', $output);
    }

    public function test_an_exception_before_commit_still_deletes_the_stored_files(): void
    {
        $org = $this->rentalTenant();
        $n = 0;
        Service::creating(function () use (&$n): void {
            if (++$n === 2) {
                throw new RuntimeException('boom');
            }
        });

        [$code, $output] = $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product(), $this->product()]), '--image-delay' => '0']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('nic nie zapisano', $output);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, CatalogImportBatch::count());
    }

    // ================================================================== 7. external_id case

    public function test_external_ids_are_matched_case_insensitively_against_the_ledger(): void
    {
        $org = $this->rentalTenant();
        $this->import($org, $this->catalogFile([$this->product(['external_id' => 'ABC', 'name' => 'Pierwszy'])]))->assertExitCode(0);

        $second = $this->catalogFile([$this->product(['external_id' => ' abc ', 'name' => 'Drugi'])]);
        $this->import($org, $second)->assertExitCode(1);
        $this->assertSame(1, $this->services($org)->count());

        $this->import($org, $second, ['--skip-existing' => true])->assertExitCode(0);
        $this->assertSame(1, $this->services($org)->count());
    }

    // ================================================================== 4. image-phase budget, lock, scratch

    private function budgetImport(float &$now, array &$sleeps, ?Closure $onSleep = null): ImportCatalog
    {
        $clock = function () use (&$now): float {
            return $now;
        };
        $sleeper = function (int $ms) use (&$now, &$sleeps, $onSleep): void {
            $sleeps[] = $ms;
            $now += $ms;
            if ($onSleep !== null) {
                $onSleep($ms);
            }
        };

        return new class(app(CatalogProductValidator::class), $clock, $sleeper) extends ImportCatalog
        {
            public function __construct(CatalogProductValidator $v, private Closure $clock, private Closure $sleeper)
            {
                parent::__construct($v);
            }

            protected function makeFetcher(ImageInspector $inspector, ImportOptions $options): SafeImageFetcher
            {
                return new SafeImageFetcher(
                    app(\App\Support\Catalog\HostResolver::class),
                    $inspector,
                    $this->sleeper,
                    $this->clock,
                    hostDelayMs: $options->imageDelayMs,
                    maxRetries: 3,
                    maxRetryWaitSeconds: 60,
                );
            }

            protected function budgetClock(): Closure
            {
                return $this->clock;
            }
        };
    }

    public function test_the_image_phase_has_a_global_deadline_that_also_truncates_sleeps(): void
    {
        config(['catalog_import.image_phase_max_seconds' => 100]);
        $org = $this->rentalTenant();
        $now = 1_000_000.0;
        $sleeps = [];
        // every request is throttled with Retry-After 60 -> the second image needs more than the budget
        Http::fake(['slow.example.net/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '60'])->push($this->png(), 200)
            ->push('', 429, ['Retry-After' => '60'])->push($this->png(), 200)]);
        $action = $this->budgetImport($now, $sleeps);

        $products = [
            $this->product(['external_id' => 'a', 'featured_image' => ['url' => 'https://slow.example.net/1.png'], 'gallery' => []]),
            $this->product(['external_id' => 'b', 'featured_image' => ['url' => 'https://slow.example.net/2.png'], 'gallery' => []]),
        ];

        $result = $action->run($org, $this->catalogFile($products), new ImportOptions(imageDelayMs: 0));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('budżet', json_encode($result->report->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertLessThanOrEqual(100_000, array_sum($sleeps), 'total sleeping never exceeds the budget');
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], File::directories(storage_path('app/tmp/catalog-import')), 'scratch cleaned up');
    }

    public function test_the_image_phase_has_a_byte_budget(): void
    {
        config(['catalog_import.image_phase_max_bytes' => strlen($this->png()) + 10]);
        $org = $this->rentalTenant();
        $now = 1.0;
        $sleeps = [];
        $action = $this->budgetImport($now, $sleeps);

        $result = $action->run($org, $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'A', 'featured_image' => ['url' => 'https://img.example.com/a.png'], 'gallery' => [['url' => 'https://img.example.com/b.png']]]),
        ]), new ImportOptions(imageDelayMs: 0));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('budżet', json_encode($result->report->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->services($org)->count());
    }

    public function test_a_repeated_url_is_counted_once_against_the_byte_budget(): void
    {
        config(['catalog_import.image_phase_max_bytes' => strlen($this->png(64, 64, 21)) + 200]);
        $org = $this->rentalTenant();
        $now = 1.0;
        $sleeps = [];

        $result = $this->budgetImport($now, $sleeps)->run($org, $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'A', 'featured_image' => ['url' => 'https://img.example.com/same.png'], 'gallery' => []]),
            $this->product(['external_id' => 'b', 'name' => 'B', 'featured_image' => ['url' => 'https://img.example.com/same.png'], 'gallery' => []]),
        ]), new ImportOptions(imageDelayMs: 0));

        $this->assertTrue($result->ok(), json_encode($result->report->errors()));
    }

    public function test_a_lock_lost_mid_run_aborts_the_import_before_anything_is_written(): void
    {
        $org = $this->rentalTenant();
        $now = 1.0;
        $sleeps = [];
        // the image host throttles once; during that sleep somebody force-releases the lock
        Http::fake(['slow.example.net/*' => Http::sequence()->push('', 429, ['Retry-After' => '1'])->push($this->png(), 200)]);
        $action = $this->budgetImport($now, $sleeps, fn () => Cache::lock("catalog-import:{$org->id}")->forceRelease());

        $result = $action->run($org, $this->catalogFile([
            $this->product(['featured_image' => ['url' => 'https://slow.example.net/1.png'], 'gallery' => []]),
        ]), new ImportOptions(imageDelayMs: 0));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('blokad', json_encode($result->report->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_stale_scratch_directories_older_than_six_hours_are_removed_at_start(): void
    {
        $org = $this->rentalTenant();
        $root = storage_path('app/tmp/catalog-import');
        File::ensureDirectoryExists($root.'/old-run');
        File::ensureDirectoryExists($root.'/fresh-run');
        file_put_contents($root.'/old-run/leftover.png', 'x');
        touch($root.'/old-run', time() - 7 * 3600);
        touch($root.'/fresh-run', time() - 3600);

        $this->import($org, $this->catalogFile([$this->product()]), ['--dry-run' => true, '--no-image-check' => true])->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($root.'/old-run');
        $this->assertDirectoryExists($root.'/fresh-run');
        File::deleteDirectory($root.'/fresh-run');
    }
}
