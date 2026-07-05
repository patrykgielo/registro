<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\RentalStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;

/**
 * Counts active obligations for an Organization across appointments, orders and rentals.
 *
 * All queries bypass the BelongsToOrganization global scope ('organization') because
 * this service runs in a super-admin context where no tenant is bound to the container.
 * Organization scoping is done explicitly via where('organization_id', $org->id).
 *
 * Decision — appointments: Appointment has its own organization_id column (confirmed in
 * $fillable) and uses the BelongsToOrganization trait. Direct column query is used instead
 * of going through service→organization or staff→organization relationships, as it is
 * both simpler and more accurate (appointments can outlive their service).
 */
class TenantObligationService
{
    /**
     * Returns a breakdown of active obligations for the given organization.
     *
     * An obligation is "active" when it is in-flight — i.e. requires ongoing attention
     * before the organization can be wound down. Completed/terminal states do NOT block closure.
     *
     * - appointments: status in (pending, confirmed)
     * - orders: status in (pending_payment, paid, confirmed, in_progress) — in-flight only.
     *   `completed` and `refunded` are terminal and do NOT count; `cancelled` is terminal too.
     *   Definition: order states with outgoing transitions per OrderStatusStateMachine.
     * - rentals: status in (held, pending, confirmed, active) — RentalStatus::blocksAvailability()
     *
     * @return array{appointments: int, orders: int, rentals: int, total: int}
     */
    public function activeObligations(Organization $org): array
    {
        $appointments = Appointment::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ])
            ->count();

        $orders = Order::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['pending_payment', 'paid', 'confirmed', 'in_progress'])
            ->count();

        $rentals = Rental::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->whereIn('status', [
                RentalStatus::Held->value,
                RentalStatus::Pending->value,
                RentalStatus::Confirmed->value,
                RentalStatus::Active->value,
            ])
            ->count();

        return [
            'appointments' => $appointments,
            'orders' => $orders,
            'rentals' => $rentals,
            'total' => $appointments + $orders + $rentals,
        ];
    }

    public function hasActiveObligations(Organization $org): bool
    {
        return $this->activeObligations($org)['total'] > 0;
    }
}
