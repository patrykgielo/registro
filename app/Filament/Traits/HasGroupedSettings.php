<?php

declare(strict_types=1);

namespace App\Filament\Traits;

use App\Support\Settings\SettingsManager;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component as SchemaComponent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Trait for Filament settings pages with per-group (per-tab) validation.
 *
 * Instead of validating the entire form when saving one tab,
 * this trait allows validation of only the active group's fields.
 *
 * Usage:
 * 1. Use this trait in your settings page
 * 2. Implement getSettingsGroups() to define groups, labels, and rules
 * 3. Call saveSettingsGroup('group_name') from each tab's save action
 *
 * @property array<string, mixed>|null $data Form state data
 */
trait HasGroupedSettings
{
    /**
     * Define settings groups configuration.
     *
     * Each group must have:
     * - 'label' (string): Human-readable name for notifications
     * - 'rules' (array): Laravel validation rules for this group's fields
     *
     * Rules use simple key names without the group prefix.
     * Example: 'business_hours_start' NOT 'booking.business_hours_start'
     *
     * @return array<string, array{label: string, rules: array<string, string|array>}>
     */
    abstract protected function getSettingsGroups(): array;

    /**
     * Validate and save a specific settings group.
     *
     * This method:
     * 1. Extracts only the group's data from form state
     * 2. Validates using group-specific rules
     * 3. Persists each setting via SettingsManager
     * 4. Clears relevant cache
     * 5. Shows success/error notification
     *
     * @param  string  $group  The group key (e.g., 'booking', 'email')
     *
     * @throws ValidationException If validation fails
     */
    public function saveSettingsGroup(string $group): void
    {
        $groups = $this->getSettingsGroups();

        if (! isset($groups[$group])) {
            Notification::make()
                ->title("Nieznana grupa ustawień: {$group}")
                ->danger()
                ->send();

            return;
        }

        // Trigger FileUpload processing (saveUploadedFiles) before reading data.
        // Without this, FileUpload state contains raw Livewire temp upload UUIDs
        // instead of the stored file paths from saveUploadedFileUsing callbacks.
        $state = [];
        $this->form->callBeforeStateDehydrated($state);

        $config = $groups[$group];

        // Build the group's data from each of ITS OWN field components' getState(),
        // not from $this->data[$group] directly. Field-level getState() (Filament\Schemas\
        // Components\Concerns\HasState::getState(), NOT the container-level
        // Schema::getState() that validates the whole form) applies that field's own
        // StateCasts to the raw Livewire state — e.g. RichEditorStateCast::get() turns the
        // raw Tiptap document into the HTML string the field's dehydrated value actually is,
        // FileUploadStateCast::get() extracts the stored path from the UUID-keyed upload
        // array. Reading $this->data[$group] raw skips all of that and validates the
        // pre-cast internal representation instead, which for RichEditor is never a string
        // — see filament-settings-pages.md "RichEditor w grupie z HasGroupedSettings".
        $groupData = $this->getGroupStateFromComponents($group);

        // Validate only this group's fields
        $validator = Validator::make($groupData, $config['rules']);

        if ($validator->fails()) {
            // Show each error as notification
            foreach ($validator->errors()->all() as $error) {
                Notification::make()
                    ->title('Błąd walidacji')
                    ->body($error)
                    ->danger()
                    ->send();
            }

            // Throw for Filament to highlight fields
            $prefixedErrors = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $prefixedErrors["data.{$group}.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($prefixedErrors);
        }

        // Persist validated data. persistSettingsGroup() calls SettingsManager::set() per
        // key, which already clears that key's real cache entry
        // (settings:tenant:{id}:{group}:{key} / settings:tenant:{id}:{group}) — a bare
        // "settings:{group}" forget here never matched that format and never cleared anything.
        $this->persistSettingsGroup($group, $groupData);

        // Success notification
        Notification::make()
            ->title($config['label'])
            ->success()
            ->send();
    }

    /**
     * Read a settings group's data from its own field components' `getState()`,
     * applying each field's StateCasts (RichEditor JSON→HTML, FileUpload UUID→path, ...)
     * instead of reading `$this->data[$group]` raw.
     *
     * This deliberately does NOT use `$this->form->getState()` — that validates the
     * ENTIRE form (all tabs), which is exactly what this trait exists to avoid (see
     * "CRITICAL: Problem z $this->form->getState()" in filament-settings-pages.md).
     * Instead it walks the schema tree once, keeps only the fields whose absolute state
     * path is `data.{$group}.<key>` (skipping deeper nesting — those belong to a
     * top-level Repeater/FileUpload field and are folded into ITS OWN getState() call),
     * and calls `getState()` on each field individually. That method
     * (Filament\Schemas\Components\Concerns\HasState::getState()) reads the raw Livewire
     * state for that one field and applies only that field's own casts — it does not
     * validate or touch any other field.
     *
     * @return array<string, mixed>
     */
    protected function getGroupStateFromComponents(string $group): array
    {
        $prefix = "data.{$group}.";
        $data = [];

        foreach ($this->form->getFlatComponents(withHidden: true) as $component) {
            if (! ($component instanceof SchemaComponent)) {
                // Filament\Actions\Action (e.g. the tab's own "Zapisz" button) —
                // not a state-bearing field.
                continue;
            }

            if (! $component->hasStatePath()) {
                continue;
            }

            $statePath = $component->getStatePath();

            if (! str_starts_with($statePath, $prefix)) {
                continue;
            }

            $relativeKey = substr($statePath, strlen($prefix));

            if (str_contains($relativeKey, '.')) {
                // Nested field inside a Repeater/complex component — handled by its
                // top-level parent's own getState() call above, not individually.
                continue;
            }

            Arr::set($data, $relativeKey, $component->getState());
        }

        return $data;
    }

    /**
     * Persist settings for a group via SettingsManager.
     *
     * @param  string  $group  Group key
     * @param  array<string, mixed>  $data  Validated group data
     */
    protected function persistSettingsGroup(string $group, array $data): void
    {
        $settingsManager = app(SettingsManager::class);

        foreach ($data as $key => $value) {
            $value = $this->normalizeFileUploadValue($value);
            $settingsManager->set("{$group}.{$key}", $value);
        }
    }

    /**
     * Normalize Filament component state for storage.
     *
     * Handles different Filament component formats:
     * - FileUpload: single file path → extract string
     * - Repeater (complex): UUID-keyed objects → re-index to numeric array
     * - Repeater (simple): UUID-keyed strings → re-index to numeric array
     * - Other arrays: pass through unchanged
     *
     * Detection logic:
     * - FileUpload paths contain '/' and end with file extension (e.g., 'settings/logos/file.svg')
     * - Simple Repeater values are user text without path characteristics
     */
    private function normalizeFileUploadValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (empty($value)) {
            return null;
        }

        $firstKey = array_key_first($value);
        $firstValue = reset($value);

        // Numeric keys: already processed (re-indexed) or simple array
        if (is_int($firstKey)) {
            // Single file path (FileUpload after save/reload)
            if (count($value) === 1 && is_string($firstValue) && $this->looksLikeFilePath($firstValue)) {
                return $firstValue;
            }

            // Repeater or other array: return as-is
            return $value;
        }

        // UUID keys: both FileUpload (raw Livewire) and Repeater use these
        if (is_string($firstKey) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $firstKey)) {
            // Repeater with array values: ['uuid' => ['field' => 'value', ...], ...]
            if (is_array($firstValue) && ! empty($firstValue)) {
                $nestedFirstKey = array_key_first($firstValue);

                // Repeater item with named fields
                if (is_string($nestedFirstKey) && ! is_numeric($nestedFirstKey)) {
                    // Detect Simple Repeater: ALL items have exactly ONE key
                    // Simple Repeater stores: ['uuid' => ['item' => 'text'], ...]
                    // Complex Repeater stores: ['uuid' => ['name' => 'X', 'icon' => 'Y'], ...]
                    $isSingleKeyRepeater = true;
                    foreach ($value as $item) {
                        if (! is_array($item) || count($item) !== 1) {
                            $isSingleKeyRepeater = false;
                            break;
                        }
                    }

                    if ($isSingleKeyRepeater) {
                        // Simple Repeater: extract the single value from each item
                        // ['uuid' => ['item' => 'text1'], ...] → ['text1', 'text2', ...]
                        return array_map(
                            fn ($item) => reset($item),
                            array_values($value)
                        );
                    }

                    // Complex Repeater: keep structure, re-index to numeric
                    // ['uuid' => ['name' => 'X', 'icon' => 'Y'], ...] → [['name' => 'X', 'icon' => 'Y'], ...]
                    return array_values($value);
                }

                // FileUpload (raw Livewire): nested numeric array with file path
                $path = reset($firstValue);

                return is_string($path) ? $path : null;
            }

            // String values: could be Simple Repeater OR FileUpload
            if (is_string($firstValue)) {
                // FileUpload: single file path
                if (count($value) === 1 && $this->looksLikeFilePath($firstValue)) {
                    return $firstValue;
                }

                // Simple Repeater with direct string values: re-index UUID keys to numeric
                return array_values($value);
            }

            return null;
        }

        return $value;
    }

    /**
     * Check if a string looks like a file path.
     *
     * FileUpload paths typically:
     * - Start with 'settings/' (our upload directory)
     * - Or contain '/' and end with a file extension
     */
    private function looksLikeFilePath(string $value): bool
    {
        // Our FileUpload stores in 'settings/logos/'
        if (str_starts_with($value, 'settings/')) {
            return true;
        }

        // General file path pattern: contains / and ends with .ext
        if (str_contains($value, '/') && preg_match('/\.\w{2,5}$/', $value)) {
            return true;
        }

        return false;
    }
}
