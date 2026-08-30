<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data fix, companion to App\Traits\NormalizesEmptyJsonToNull's new
 * `normalizeEmptyHtmlToNullFields()` half (PanelWalkthroughTest, 2026-08-30):
 * that trait only guards WRITES from this moment forward. Rows that already
 * got `<p></p>` (or `<p><br></p>`, `<p>&nbsp;</p>`, pure whitespace) written
 * into `body` by a no-op "Zapisz" before the trait existed stay broken until
 * either this migration runs or someone happens to re-save the record —
 * `@if($model->body)` in home.blade.php:39, default.blade.php:26,
 * full-width.blade.php:22, minimal.blade.php:20 and portfolio/show.blade.php:55
 * treats that string as present and renders an empty padded content
 * container where nothing should show.
 *
 * Same emptiness rule as the trait, deliberately duplicated rather than
 * calling it: per migrations.md, a migration's behavior is pinned to the
 * schema at the moment it runs, not to application code that can change
 * shape later (see 2026_08_30_140000's docblock for the same reasoning
 * applied to specs shape). `img`/`iframe`/`video`/`audio`/`source`/`embed`
 * are preserved as non-empty on purpose — a body holding only an image or
 * embed is real content, not a blank paragraph.
 */
return new class extends Migration
{
    private const TABLES = ['pages', 'posts', 'portfolio_items', 'promotions', 'services'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNotNull('body')
                ->orderBy('id')
                ->select(['id', 'body'])
                ->chunkById(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        if (self::isEmptyHtml((string) $row->body)) {
                            DB::table($table)->where('id', $row->id)->update(['body' => null]);
                        }
                    }
                });
        }
    }

    /**
     * Irreversible: rows converted to NULL here are indistinguishable, after
     * the fact, from rows that were already genuinely NULL before up() ran —
     * there is no marker column recording which ones this migration touched.
     * Per migrations.md, a data-only migration that cannot be reversed must
     * say so explicitly rather than ship a no-op down().
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'This migration is a data-only fix (empty-HTML body -> NULL) and cannot be rolled back safely: '.
            'converted rows are indistinguishable from rows that were already NULL.'
        );
    }

    private static function isEmptyHtml(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded, '<img><iframe><video><audio><source><embed>');
        $stripped = str_replace("\xC2\xA0", ' ', $stripped);

        return trim($stripped) === '';
    }
};
