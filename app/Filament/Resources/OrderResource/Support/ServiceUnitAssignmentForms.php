<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Support;

use App\Enums\ServiceUnitStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceUnit;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Faza 3 kroki 3.6/3.7 — the form schema shared by all FOUR "wydanie"/"zwrot"
 * Filament call sites (OrderResource row action, EditOrder header action,
 * one pair each), so none of them can silently diverge. See OrderService::
 * handOver()/completeReturn() for the domain validation this form's
 * server-side self-defense (see the class docblocks below) is backed by —
 * the service layer is the actual source of truth, not this form.
 */
class ServiceUnitAssignmentForms
{
    /**
     * One optional Select per order item, keyed `unit_assignments.{itemId}`
     * — plain array keys, not a Repeater: the set of order items on an
     * order is fixed (not addable/removable by the user), so a Repeater's
     * extra machinery (state paths per row, add/delete controls) would only
     * add complexity with no matching requirement.
     *
     * Options are scoped to `status = available` units of the item's own
     * service — Filament's single-value Select re-validates the submitted
     * id against this exact query server-side (filament-resources.md,
     * "Select pojedynczy broni się sam"), and BelongsToOrganization's global
     * scope on ServiceUnit already excludes cross-tenant rows from it. No
     * "currently assigned but now-invisible" trap applies here (unlike
     * returnFields() below) because at handover time nothing has been
     * assigned to this item yet — every field starts at its Filament
     * default of null.
     *
     * A companion TextInput ('unit_identifiers.{itemId}') appears once a
     * unit WITHOUT an identifier is picked — plan-wdrozenia.md krok 3.6:
     * "Jeśli wybrana sztuka nie ma jeszcze numeru — pracownik wpisuje go na
     * miejscu", the product's own answer to "numer jest opcjonalny": the
     * catalogue fills in through USE, not a one-off inventory count. See
     * OrderService::handOver()'s own docblock for why this only ever fills
     * a null identifier, never overwrites an existing one.
     *
     * @return array<int, Fieldset>
     */
    public static function handoverFields(Order $order): array
    {
        return self::itemsOf($order)->map(function (OrderItem $item) {
            return Fieldset::make(self::itemLabel($item))
                ->columns(1)
                ->schema([
                    Select::make("unit_assignments.{$item->id}")
                        ->label('Wydawany egzemplarz')
                        ->placeholder('— bez przypisania —')
                        ->options(fn () => self::optionsFor($item))
                        ->live()
                        ->searchable(),

                    TextInput::make("unit_identifiers.{$item->id}")
                        ->label('Nadaj numer własny')
                        ->helperText('Wybrana sztuka nie ma jeszcze numeru — możesz go nadać teraz, przy wydaniu.')
                        ->maxLength(255)
                        ->visible(function (Get $get) use ($item): bool {
                            $selected = $get("unit_assignments.{$item->id}");

                            if ($selected === null) {
                                return false;
                            }

                            return self::unitIdentifierIsNull((int) $selected, $item);
                        }),
                ]);
        })->all();
    }

    /**
     * One Select per order item, defaulting to whatever was assigned at
     * handover (`$item->service_unit_id`) — a staff member who touches
     * nothing submits the SAME value, so OrderService::completeReturn()
     * sees no mismatch and no extra confirmation is required (requirement:
     * zero extra clicks for the common case).
     *
     * Options include the CURRENTLY assigned unit even if its status is no
     * longer 'available' (e.g. sent to maintenance after handover) — the
     * exact trap filament-resources.md's "Select pojedynczy broni się sam"
     * section documents: without this, the field's own unchanged default
     * value would fail `Rule::in()` and block saving the ENTIRE form, not
     * just this field.
     *
     * Each Select's helper text is LIVE and shows the explicit "wydano X,
     * zwracany jest Y" comparison the moment the two diverge — ClickUp
     * 123k99cu2b4 requirement #1 ("oba numery widoczne"), not just a generic
     * "differs from handover" notice.
     *
     * A companion optional TextInput ('return_identifiers.{itemId}') appears
     * when the CURRENTLY SELECTED returned unit has no identifier — the
     * ticket's own edge case: a unit handed out without a number has nothing
     * to compare, so the suggested (not enforced) fix is to ask for one at
     * the natural moment it's finally back in hand.
     *
     * A trailing Checkbox ('mismatch_confirmed') is appended, visible only
     * once ANY item's selected value differs from what it was handed out
     * with, and REQUIRED (`->rule('accepted')`) in that state — ClickUp
     * 123k99cu2b4 requirement #2, "nie może dać się kliknąć dalej
     * przypadkiem". Filament skips validation on a hidden component
     * entirely (filament-resources.md, "Section 'Hasło' ->visibleOn
     * ('create')"), so the rule never fires while there is no mismatch to
     * confirm. This is stricter than the sibling `amount_mismatch_confirmed`
     * on `record_offline_payment` (informative-only there) — the ticket for
     * THIS action explicitly demands the harder guarantee; the domain-layer
     * check in OrderService::completeReturn() stays as the actual
     * enforcement point either way, this is defense-in-depth on top of it,
     * not instead of it.
     *
     * @return array<int, Fieldset|Checkbox>
     */
    public static function returnFields(Order $order): array
    {
        $items = self::itemsOf($order);

        $fields = $items->map(function (OrderItem $item) {
            return Fieldset::make(self::itemLabel($item))
                ->columns(1)
                ->schema([
                    Select::make("returned_units.{$item->id}")
                        ->label('Faktycznie zwrócony egzemplarz')
                        ->helperText(fn (Get $get) => self::returnHelperText($item, $get))
                        ->placeholder('— bez przypisania —')
                        ->options(fn () => self::optionsFor($item, includeUnitId: $item->service_unit_id))
                        ->default($item->service_unit_id)
                        ->live()
                        ->searchable(),

                    TextInput::make("return_identifiers.{$item->id}")
                        ->label('Nadaj numer własny')
                        ->helperText('Zwracana sztuka nie ma jeszcze numeru — możesz go nadać teraz, przy zwrocie.')
                        ->maxLength(255)
                        ->visible(function (Get $get) use ($item): bool {
                            $selected = $get("returned_units.{$item->id}");

                            if ($selected === null) {
                                return false;
                            }

                            return self::unitIdentifierIsNull((int) $selected, $item);
                        }),
                ]);
        })->all();

        $fields[] = Checkbox::make('mismatch_confirmed')
            ->label('Potwierdzam, że wybrany egzemplarz różni się od wydanego')
            ->live()
            ->visible(fn (Get $get) => self::hasMismatch($items, $get))
            ->rule('accepted')
            ->validationMessages([
                'accepted' => 'Musisz potwierdzić niezgodność egzemplarza, aby zapisać zwrot.',
            ]);

        return $fields;
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private static function hasMismatch(Collection $items, Get $get): bool
    {
        foreach ($items as $item) {
            $selected = $get("returned_units.{$item->id}");
            $selected = $selected !== null ? (int) $selected : null;

            if ($selected !== $item->service_unit_id) {
                return true;
            }
        }

        return false;
    }

    private static function returnHelperText(OrderItem $item, Get $get): string
    {
        $handedOutLabel = $item->service_unit_id !== null
            ? ($item->serviceUnit?->display_label ?? "Egzemplarz #{$item->service_unit_id}")
            : null;

        $selected = $get("returned_units.{$item->id}");
        $selected = $selected !== null ? (int) $selected : null;

        if ($handedOutLabel === null) {
            return 'Nie przypisano egzemplarza przy wydaniu.';
        }

        if ($selected === $item->service_unit_id) {
            return "Wydano: {$handedOutLabel}";
        }

        $returnedLabel = $selected !== null
            ? (ServiceUnit::find($selected)?->display_label ?? "Egzemplarz #{$selected}")
            : '— bez przypisania —';

        return "⚠ Niezgodność — wydano: {$handedOutLabel}, zwracane: {$returnedLabel}.";
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private static function itemsOf(Order $order): Collection
    {
        return $order->items()->with('service', 'serviceUnit')->get();
    }

    private static function itemLabel(OrderItem $item): string
    {
        return sprintf(
            '%s (%s – %s)',
            $item->service_name,
            $item->start_date->format('d.m.Y'),
            $item->end_date->format('d.m.Y'),
        );
    }

    /**
     * $item is only used to scope the lookup to the item's own service —
     * cheap defense against a crafted unit id belonging to a different
     * service ever flipping the TextInput visible (real enforcement of
     * "belongs to this item's service" still lives in OrderService::
     * resolveUnitForItem(), same as everywhere else in this class).
     */
    private static function unitIdentifierIsNull(int $unitId, OrderItem $item): bool
    {
        return ServiceUnit::query()
            ->where('id', $unitId)
            ->where('service_id', $item->service_id)
            ->whereNull('identifier')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private static function optionsFor(OrderItem $item, ?int $includeUnitId = null): array
    {
        return ServiceUnit::query()
            ->where('service_id', $item->service_id)
            ->where(fn (Builder $q) => $q
                ->where('status', ServiceUnitStatus::Available->value)
                ->when($includeUnitId, fn (Builder $withCurrent) => $withCurrent->orWhere('id', $includeUnitId)))
            ->get()
            ->mapWithKeys(fn (ServiceUnit $unit) => [$unit->id => $unit->display_label])
            ->all();
    }
}
