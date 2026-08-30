<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Collapses an empty-array value on a nullable JSON column back to NULL before save.
 *
 * Filament's Repeater/FileUpload/KeyValue components all dehydrate an "I touched this field
 * but left it empty" state to `[]`, never `null` -- there is no form interaction that produces
 * `null` for these components. Applied model-level (not per-component) so it protects EVERY
 * write path (Filament, console commands, API, tinker), not just the specific forms that
 * exposed it first (LocationResource's opening_hours/gallery, ReminderConfigResource's
 * settings). Without this, a no-op "Zapisz" on a record whose column is genuinely NULL turns
 * it into `[]` -- a real (if silent) mutation that trips `Auditable` into logging a change that
 * never happened, and bumps `updated_at` for nothing (see PanelWalkthroughTest, 2026-08-30).
 *
 * NULL and `[]` are not distinguished anywhere this project reads these columns -- every
 * consumer already does `$model->field ?? []` (location-card.blade.php, portfolio show.blade.php)
 * or an `array` cast that returns `null` for a NULL column, so collapsing one into the other
 * loses no information.
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
        });
    }

    /**
     * @return array<int, string> column names (must have a nullable JSON/array cast)
     */
    abstract protected function normalizeEmptyJsonToNullFields(): array;
}
