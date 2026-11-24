<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Filament\Resources\ServiceResource;
use App\Filament\Traits\SavesFormPerTab;
use App\Filament\Traits\StaysOnPageAfterSave;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Components\Attributes\Exposed;
use Livewire\Attributes\On;

class EditService extends EditRecord
{
    use SavesFormPerTab;
    use StaysOnPageAfterSave;

    protected static string $resource = ServiceResource::class;

    /**
     * `duration_minutes` is a Hidden in "Podstawowe" (it must dehydrate for every type, see ServiceResource), but the
     * hours/minutes inputs that edit it live in "Cena i czas trwania" and are not dehydrated themselves. So saving
     * THAT tab persists the duration, and saving "Podstawowe" must not persist a duration edit made in the other tab.
     *
     * @return array<string, string>
     */
    protected function tabPathOwnershipOverrides(): array
    {
        return ['duration_minutes' => 'cena-i-czas-trwania'];
    }

    protected function tabSavedEventName(): string
    {
        return 'service-form-tab-saved';
    }

    /**
     * Which tabs (and fields) are visible is decided by `data.service_type`, a Livewire property the client can write
     * (the Select is only `disabled()`, which does not lock it). The type is immutable, so the record is the truth:
     * without this a rental spoofed to `time_slot` would validate and write the time-slot tab's fields.
     */
    private function pinServiceType(): void
    {
        $this->data['service_type'] = $this->record->service_type->value;
    }

    protected function beforeSavingTab(): void
    {
        $this->pinServiceType();
    }

    #[Exposed]
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->pinServiceType();

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * The form binds only `metadata.specs`, and Filament's getState() returns
     * only bound paths — saving that as-is rewrites the whole `metadata`
     * column to `{specs: [...]}` and drops every other key (catalog-import
     * `image_sources` credits, anything a seeder/API added). Merge the form's
     * keys OVER what is stored: the form wins per top-level key, so clearing
     * the specs (`[]`) still clears them. Top-level `array_replace`, not a
     * recursive merge — `specs` is a list, a recursive merge would resurrect
     * deleted rows. Read from the DB, not `$this->record`: the form may have
     * been open for a long time and the in-memory record is its stale copy.
     * A form that sends no `metadata` at all (time_slot) is left untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! array_key_exists('metadata', $data)) {
            return $data;
        }

        // In-memory record only when the row is gone; a NULL in the DB is a real value
        // (it must not resurrect the stale keys the form was opened with).
        $fresh = $this->record->fresh();
        $stored = $fresh ? $fresh->metadata : $this->record->metadata;

        $data['metadata'] = array_replace(
            is_array($stored) ? $stored : [],
            is_array($data['metadata']) ? $data['metadata'] : [],
        );

        return $data;
    }

    /**
     * Routing writes the stock row of the primary location (re-activating it, creating it if missing) and the
     * `quantity_total` mirror. A full save always did it; a single-tab save does it ONLY when the tab sent
     * `quantity_total` - otherwise saving e.g. "Treść" would rewrite stock the user never touched here.
     */
    protected function afterSave(): void
    {
        if ($this->isSavingSingleTab() && ! $this->tabSaveSentKey('quantity_total')) {
            return;
        }

        RouteQuantityFieldToPrimaryLocationStock::handle($this->record);
    }

    /**
     * The "Ilość w magazynie" field (ServiceResource.php ~:277) is hydrated
     * once into $this->data at mount — a write from the sibling "Egzemplarze"
     * relation manager (UnitsRelationManager, a SEPARATE Livewire component)
     * never reaches it on its own. Livewire's dispatch() is page-wide, not
     * parent/child-scoped, so this fires regardless of nesting the moment a
     * unit is created/edited/deleted there. Reads the mirror straight from
     * the DB (Service::recalculateQuantityTotal() already committed by the
     * time ServiceUnitObserver's transaction returns), not from $this->record,
     * which is the same stale in-memory instance the form was built from.
     */
    #[On('service-unit-stock-changed')]
    public function refreshQuantityTotalField(): void
    {
        $this->data['quantity_total'] = $this->record->fresh()->quantity_total;
    }
}
