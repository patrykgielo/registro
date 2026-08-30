<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executes the real up()/down() of
 * 2026_08_30_150000_normalize_empty_body_html_to_null.php against fixture
 * rows — not a static regex on the file's source.
 *
 * down() intentionally throws \RuntimeException (irreversible data-only fix,
 * per migrations.md), which rules out the usual `migrate:rollback --path=...`
 * / `migrate --path=...` round-trip other migration tests in this directory
 * use to get pre-migration fixtures: rolling this one back through the
 * migrator would itself throw. Instead, the migration file (its last
 * statement is `return new class extends Migration {...}`) is `require`d
 * directly and its up()/down() methods are invoked directly — this still
 * executes the real methods against the real (SQLite, in this suite)
 * connection, it just bypasses the migrator's bookkeeping of "already ran"
 * instead of fighting it.
 *
 * Fixture rows are inserted via DB::table(...)->insert(), never through the
 * Eloquent models — Page/Post/PortfolioItem/Promotion/Service all carry
 * App\Traits\NormalizesEmptyJsonToNull's `normalizeEmptyHtmlToNullFields()`
 * now, which would normalize an empty body away before it ever reached the
 * database, defeating the point of these fixtures (they simulate rows
 * written BEFORE that trait existed).
 */
class NormalizeEmptyBodyHtmlToNullMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__.'/../../../database/migrations/2026_08_30_150000_normalize_empty_body_html_to_null.php';

    private const TABLES = ['pages', 'posts', 'portfolio_items', 'promotions', 'services'];

    public function test_up_converts_every_empty_html_shape_to_null_on_every_table(): void
    {
        $ids = [];

        foreach (self::TABLES as $table) {
            $ids[$table] = [
                'p_br' => $this->insertRow($table, '<p></p>'),
                'p_break' => $this->insertRow($table, '<p><br></p>'),
                'p_nbsp' => $this->insertRow($table, '<p>&nbsp;</p>'),
                'whitespace' => $this->insertRow($table, "   \n  "),
            ];
        }

        $this->migration()->up();

        foreach (self::TABLES as $table) {
            foreach ($ids[$table] as $shape => $id) {
                $this->assertNull(
                    DB::table($table)->where('id', $id)->value('body'),
                    "{$table}#{$id} ({$shape}) must be normalized to NULL"
                );
            }
        }
    }

    public function test_up_leaves_already_null_and_genuinely_non_empty_bodies_untouched(): void
    {
        $ids = [];

        foreach (self::TABLES as $table) {
            $ids[$table] = [
                'already_null' => $this->insertRow($table, null),
                'real_text' => $this->insertRow($table, '<p>Realna treść, nie do ruszenia.</p>'),
            ];
        }

        $this->migration()->up();

        foreach (self::TABLES as $table) {
            $this->assertNull(DB::table($table)->where('id', $ids[$table]['already_null'])->value('body'));
            $this->assertSame(
                '<p>Realna treść, nie do ruszenia.</p>',
                DB::table($table)->where('id', $ids[$table]['real_text'])->value('body')
            );
        }
    }

    /**
     * The exact risk the task calls out: an image-only or embed-only body
     * has no readable text but is real content, not a blank paragraph.
     */
    public function test_up_leaves_image_only_and_iframe_only_bodies_untouched(): void
    {
        $imageId = $this->insertRow('pages', '<p><img src="/storage/pages/photo.jpg" alt=""></p>');
        $iframeId = $this->insertRow('pages', '<iframe src="https://www.youtube.com/embed/xyz"></iframe>');

        $this->migration()->up();

        $this->assertStringContainsString('photo.jpg', DB::table('pages')->where('id', $imageId)->value('body'));
        $this->assertStringContainsString('youtube.com', DB::table('pages')->where('id', $iframeId)->value('body'));
    }

    public function test_down_throws_and_leaves_data_untouched(): void
    {
        $id = $this->insertRow('pages', '<p></p>');
        $this->migration()->up();
        $this->assertNull(DB::table('pages')->where('id', $id)->value('body'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be rolled back safely');

        try {
            $this->migration()->down();
        } finally {
            $this->assertNull(
                DB::table('pages')->where('id', $id)->value('body'),
                'a failed/refused down() must not have touched the already-normalized row'
            );
        }
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require self::MIGRATION_PATH;
    }

    private function insertRow(string $table, ?string $body): int
    {
        $title = ucfirst($table).' '.uniqid();
        $slug = \Illuminate\Support\Str::slug($title);

        $columns = $table === 'services'
            ? [
                'name' => $title,
                'slug' => $slug,
                'body' => $body,
                'duration_minutes' => 0,
                'price' => 0,
                'is_active' => true,
                'sort_order' => 0,
            ]
            : [
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
            ];

        return DB::table($table)->insertGetId([
            ...$columns,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
