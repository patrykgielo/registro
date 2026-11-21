<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Filament\Resources\ServiceResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditService extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = ServiceResource::class;

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

    protected function afterSave(): void
    {
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
