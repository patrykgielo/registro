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
