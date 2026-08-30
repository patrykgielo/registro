<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Collapses an empty-array value on a nullable JSON column, or an empty-HTML value on a
 * nullable text column, back to NULL before save.
 *
 * Filament's Repeater/FileUpload/KeyValue components all dehydrate an "I touched this field
 * but left it empty" state to `[]`, never `null` -- there is no form interaction that produces
 * `null` for these components. RichEditor has the same problem in a different shape: an empty
 * editor dehydrates to `<p></p>` (a non-empty, truthy string), never `null` or `''`. Applied
 * model-level (not per-component) so it protects EVERY write path (Filament, console commands,
 * API, tinker), not just the specific forms that exposed it first (LocationResource's
 * opening_hours/gallery, ReminderConfigResource's settings, and -- for the HTML half --
 * Page/Post/PortfolioItem/Promotion/Service's `body` RichEditor). Without this, a no-op "Zapisz"
 * on a record whose column is genuinely NULL turns it into `[]`/`<p></p>` -- a real (if silent)
 * mutation that trips `Auditable` into logging a change that never happened, bumps `updated_at`
 * for nothing, and for `body` specifically makes 5 CMS layout partials that guard rendering with
 * `@if($model->body)` render an empty padded container where there used to be nothing (see
 * PanelWalkthroughTest, 2026-08-30).
 *
 * NULL and `[]` are not distinguished anywhere this project reads the JSON columns -- every
 * consumer already does `$model->field ?? []` (location-card.blade.php, portfolio show.blade.php)
 * or an `array` cast that returns `null` for a NULL column, so collapsing one into the other
 * loses no information. Same reasoning for the HTML columns: `services/show.blade.php` already
 * guards `body` with `trim(strip_tags($service->body)) === ''` instead of a plain truthy check
 * -- proof this exact "empty paragraph is truthy" shape was hit before and patched at one call
 * site instead of the source. Normalizing at the source makes that call-site patch redundant
 * (kept anyway -- see its own comment for why).
 */
trait NormalizesEmptyJsonToNull
{
    public static function bootNormalizesEmptyJsonToNull(): void
    {
        static::saving(function (self $model) {
            foreach ($model->normalizeEmptyJsonToNullFields() as $field) {
                if ($model->getAttribute($field) === []) {
                    $model->setAttribute($field, null);
                }
            }

            foreach ($model->normalizeEmptyHtmlToNullFields() as $field) {
                $value = $model->getAttribute($field);

                if (is_string($value) && $value !== '' && static::isEmptyHtmlValue($value)) {
                    $model->setAttribute($field, null);
                }
            }
        });
    }

    /**
     * @return array<int, string> column names (must have a nullable JSON/array cast)
     */
    protected function normalizeEmptyJsonToNullFields(): array
    {
        return [];
    }

    /**
     * @return array<int, string> column names (nullable text/string column holding RichEditor HTML)
     */
    protected function normalizeEmptyHtmlToNullFields(): array
    {
        return [];
    }

    /**
     * `<p></p>`, `<p><br></p>`, `<p>&nbsp;</p>` and pure whitespace all count as empty. A tag
     * that can carry non-text content (`img`/`iframe`/`video`/`audio`/`source`/`embed`) does
     * NOT count as empty even with zero text around it -- `<p><img src="..."></p>` is real
     * content, not a blank paragraph, and must survive this normalization untouched.
     */
    private static function isEmptyHtmlValue(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded, '<img><iframe><video><audio><source><embed>');
        $stripped = str_replace("\xC2\xA0", ' ', $stripped); // &nbsp; decodes to U+00A0, not a plain space

        return trim($stripped) === '';
    }
}
