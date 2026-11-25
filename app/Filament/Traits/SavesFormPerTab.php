<?php

declare(strict_types=1);

namespace App\Filament\Traits;

use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Per-tab save for a Filament EditRecord page whose form is one `Tabs` component.
 *
 * `saveTab('<tab key>')` validates and dehydrates ONLY the fields of that tab (Filament's
 * `saveFormComponentOnly()`), so the other tabs' columns - and JSON keys, when the page merges them - are not
 * touched, while their unsaved edits stay in `$this->data`. The page's own full `save()` is unchanged.
 *
 * Tab key accepted by `saveTab()` (same value `persistTabInQueryString()` puts in `?tab=`):
 *   - the relative key `getKey(isAbsolute: false)`, e.g. `cena-i-magazyn::data::tab` (this is what
 *     `getTabSaveMap()` is keyed by - the 5b UI should send it);
 *   - the absolute key `form.cena-i-magazyn::data::tab`;
 *   - the bare slug `cena-i-magazyn` (everything before the first `::`).
 * Anything else, and any tab that is hidden right now, is refused with 403 before anything is written.
 *
 * Traps of `saveFormComponentOnly()` (vendor EditRecord ~:219) that this trait absorbs or that callers must know:
 *   - hook order differs from `save()`: `afterValidate` -> `mutateFormDataBeforeSave` -> `beforeSave` (not
 *     beforeSave-inside-getState);
 *   - no `RecordUpdated`/`RecordSaved` event and no notification (we send our own);
 *   - a `Halt` is a SILENT return - success is detected by a flag set in the `afterSave` hook;
 *   - relationships are saved BEFORE the state is dehydrated;
 *   - it does not restore the tab's container when it throws (restored here);
 *   - `rememberData()` hashes the WHOLE `$this->data`, so `savedDataHash` says nothing about which tab is saved.
 *
 * There is NO database transaction around a save unless the panel calls `->databaseTransactions()` (this one does not):
 * a failure after the UPDATE leaves the values written. So after ANY failure that is not a validation error the DB is
 * the truth: the in-memory record is reloaded from it, whether or not the UPDATE ran. Livewire re-fetches the model on
 * every request, so that only matters within one request (two saves on one page object).
 *
 * A using page must not override `handleRecordUpdate()`: the trait wraps it (ownership overrides, sent-key record).
 * The model's `saving` hooks run on the WHOLE model, exactly as in a full save, so "other tabs' columns untouched"
 * means byte-identical for rows that are already normalised.
 */
trait SavesFormPerTab
{
    /** Request-scoped (protected: Livewire does not sync it). Short key of the tab being saved, null during a full `save()`. */
    protected ?string $savingTabShortKey = null;

    protected bool $tabSaveCompleted = false;

    /** @var array<int, string> top-level attributes handed to the model by the current tab save */
    protected array $tabSaveSentKeys = [];

    /**
     * Dehydrated state paths (relative to the form, e.g. `duration_minutes`, `metadata.specs`) that are persisted by
     * a tab OTHER than the one that holds their field: path => short key (slug) of the owning tab. A field can be
     * unreachable from the tab where the user edits it (a Hidden stays in an always-visible tab, the inputs that
     * write it live in another). On the owner's save the value is injected from the form state, on any other tab's
     * save it is dropped. Not applied by a full `save()`.
     *
     * @return array<string, string>
     */
    protected function tabPathOwnershipOverrides(): array
    {
        return [];
    }

    /**
     * Runs first in `saveTab()`, before the tab is resolved: the place to overwrite client-writable form state that
     * decides which tabs are visible (e.g. a type selector that is only disabled, not locked).
     */
    protected function beforeSavingTab(): void {}

    /** Name of the browser event dispatched after a successful tab save (payload: `tab`, `savedAt`). */
    protected function tabSavedEventName(): string
    {
        return 'form-tab-saved';
    }

    #[Exposed]
    public function saveTab(string $tabKey): void
    {
        $this->beforeSavingTab();
        $this->assertOwnershipOverridesPointAtTabs();

        $tab = $this->resolveSaveableTab($tabKey);

        $key = $tab->getKey(isAbsolute: false);
        $label = (string) $tab->getLabel();
        $container = $tab->getContainer();

        $this->savingTabShortKey = $this->shortKey($tab);
        $this->tabSaveCompleted = false;
        $this->tabSaveSentKeys = [];

        try {
            $this->saveFormComponentOnly($tab);
        } catch (ValidationException $exception) {
            // Filament's validate() already dispatched `form-validation-error` and Livewire puts the errors in the bag.
            throw $exception;
        } catch (Throwable $exception) {
            $this->discardUnsavedRecordChanges();

            throw $exception;
        } finally {
            $tab->container($container);

            $completed = $this->tabSaveCompleted;
            $this->savingTabShortKey = null;
            $this->tabSaveCompleted = false;
        }

        if (! $completed) {
            return; // a Halt: whatever halted it already told the user
        }

        Notification::make()
            ->success()
            ->title('Zapisano: '.$label)
            ->duration(3000)
            ->send();

        $this->dispatch($this->tabSavedEventName(), tab: $key, savedAt: now()->toIso8601String());
    }

    /**
     * Tabs shown right now: relative tab key => label and the dehydrated state paths that saving that tab persists
     * (nested repeater/builder rows are not listed - the repeater's own path is). For the 5b sticky bar.
     *
     * @return array<string, array{label: string, paths: array<int, string>}>
     */
    #[Unexposed]
    public function getTabSaveMap(): array
    {
        $this->assertOwnershipOverridesPointAtTabs();

        $map = [];
        $shortKeys = [];

        foreach ($this->getFormTabs() as $tab) {
            if ($tab->isHidden()) {
                continue;
            }

            $paths = [];
            $this->collectDehydratedPaths($tab->getChildSchema(), $paths);

            $key = $tab->getKey(isAbsolute: false);
            $map[$key] = ['label' => (string) $tab->getLabel(), 'paths' => $paths];
            $shortKeys[$this->shortKey($tab)] = $key;
        }

        foreach ($this->tabPathOwnershipOverrides() as $path => $ownerShortKey) {
            foreach ($map as $key => $entry) {
                $map[$key]['paths'] = array_values(array_diff($entry['paths'], [$path]));
            }

            if (isset($shortKeys[$ownerShortKey])) {
                $map[$shortKeys[$ownerShortKey]]['paths'][] = $path;
            }
        }

        return $map;
    }

    /**
     * The final `$data` of a tab save: the ownership overrides applied and the sent keys recorded, right before the
     * model is updated. A full `save()` passes through untouched.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($this->savingTabShortKey !== null) {
            $data = $this->applyTabPathOwnership($data);
            $this->tabSaveSentKeys = array_keys($data);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    /** Called by Filament's `callHook('afterSave')` (hook name = `after` + hook + trait basename); only reached on success. */
    protected function afterSaveSavesFormPerTab(): void
    {
        if ($this->savingTabShortKey !== null) {
            $this->tabSaveCompleted = true;
        }
    }

    /** True while a single tab is being saved (false for the full `save()`). */
    protected function isSavingSingleTab(): bool
    {
        return $this->savingTabShortKey !== null;
    }

    /** Whether the current tab save handed this top-level attribute to the model. */
    protected function tabSaveSentKey(string $attribute): bool
    {
        return in_array($attribute, $this->tabSaveSentKeys, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyTabPathOwnership(array $data): array
    {
        foreach ($this->tabPathOwnershipOverrides() as $path => $ownerShortKey) {
            if ($ownerShortKey === $this->savingTabShortKey) {
                if (Arr::has($this->data, $path)) {
                    Arr::set($data, $path, Arr::get($this->data, $path));
                }

                continue;
            }

            Arr::forget($data, $path);
        }

        return $data;
    }

    /**
     * The tabs of the form itself, hidden ones included. Walks the layout and STOPS at fields: the Builder blocks
     * carry their own `Tab`s (`tresc::data.content.<uuid>.data::tab`) that `getFlatComponents()` would list - one of
     * them would answer to the short key `tresc` and, by its client-known uuid, to a crafted full key.
     *
     * @return array<int, Tab>
     */
    protected function getFormTabs(): array
    {
        $tabs = [];

        $this->collectFormTabs($this->getSchema('form'), $tabs);

        return $tabs;
    }

    /**
     * @param  array<int, Tab>  $tabs
     */
    private function collectFormTabs(Schema $schema, array &$tabs): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                continue;
            }

            if ($component instanceof Tab) {
                $tabs[] = $component;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $this->collectFormTabs($child, $tabs);
            }
        }
    }

    /**
     * An override whose owner slug matches no tab (a renamed label) would silently strip the path from every save.
     * Hidden tabs count: a rental's owner of `duration_minutes` is a tab it does not show.
     */
    private function assertOwnershipOverridesPointAtTabs(): void
    {
        $slugs = array_map(fn (Tab $tab): string => $this->shortKey($tab), $this->getFormTabs());

        foreach ($this->tabPathOwnershipOverrides() as $path => $ownerShortKey) {
            if (! in_array($ownerShortKey, $slugs, true)) {
                throw new LogicException("Tab ownership override for `{$path}` names the tab `{$ownerShortKey}`, which does not exist (tabs: ".implode(', ', $slugs).').');
            }
        }
    }

    protected function resolveSaveableTab(string $tabKey): Tab
    {
        foreach ($this->getFormTabs() as $tab) {
            if (! in_array($tabKey, [$tab->getKey(isAbsolute: false), $tab->getKey(), $this->shortKey($tab)], true)) {
                continue;
            }

            abort_if($tab->isHidden(), 403);

            return $tab;
        }

        abort(403);
    }

    protected function shortKey(Tab $tab): string
    {
        return Str::before($tab->getKey(isAbsolute: false), '::');
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function collectDehydratedPaths(Schema $schema, array &$paths): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: false) as $component) {
            if ($component instanceof Field) {
                if ($component->isDehydrated()) {
                    $paths[] = (string) $component->getStatePath(isAbsolute: false);
                }

                continue; // the rows of a repeater/builder are part of the field's own path
            }

            foreach ($component->getChildSchemas() as $child) {
                $this->collectDehydratedPaths($child, $paths);
            }
        }
    }

    /**
     * After a failed tab save the DB is the truth, whether or not the UPDATE ran (there is no transaction to undo it).
     * Reload from it: a reset to the model's raw original would be wrong when the failure came after the UPDATE
     * (memory old, DB new). The raw-original reset is only the fallback when the reload itself fails.
     */
    private function discardUnsavedRecordChanges(): void
    {
        $record = $this->getRecord();

        if (! $record->exists) {
            return;
        }

        try {
            $record->refresh();
        } catch (Throwable) {
            $record->setRawAttributes($record->getRawOriginal(), sync: true);
            $record->unsetRelations();
        }
    }
}
