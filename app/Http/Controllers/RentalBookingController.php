<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RentalStatus;
use App\Enums\ServiceType;
use App\Exceptions\RentalUnavailableException;
use App\Http\Requests\Rental\StoreRentalStep1Request;
use App\Http\Requests\Rental\StoreRentalStep2Request;
use App\Models\Rental;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RentalBookingController extends Controller
{
    public function __construct(
        protected RentalAvailabilityService $availabilityService
    ) {}

    public function show(Request $request, Service $service)
    {
        $this->guardRentalService($service);

        $sessionKey = "rental_booking.{$service->id}";
        $rentalId = session("{$sessionKey}.rental_id");
        $existingHold = $rentalId ? Rental::find($rentalId) : null;

        // Pre-fill from existing hold if still valid
        $step1 = [];
        if ($existingHold && $existingHold->status === RentalStatus::Held && ! $existingHold->held_until?->isPast()) {
            $step1 = [
                'start_date' => $existingHold->start_date->format('Y-m-d'),
                'end_date' => $existingHold->end_date->format('Y-m-d'),
                'quantity' => $existingHold->quantity,
            ];
        }

        // Fallback: pre-fill from query params when no active hold
        if (empty($step1) && $request->has('start_date') && $request->has('end_date')) {
            $startRaw = $request->query('start_date');
            $endRaw = $request->query('end_date');

            if (is_string($startRaw) && is_string($endRaw)
                && Carbon::hasFormat($startRaw, 'Y-m-d') && Carbon::hasFormat($endRaw, 'Y-m-d')) {
                $start = Carbon::parse($startRaw);
                $end = Carbon::parse($endRaw);

                if ($start->gte(today()) && $end->gte($start)) {
                    $step1 = [
                        'start_date' => $startRaw,
                        'end_date' => $endRaw,
                        'quantity' => min(max(1, (int) $request->query('quantity', 1)), $service->quantity_total ?? 100),
                    ];
                }
            }
        }

        return view('rental.step1', [
            'service' => $service,
            'step1' => $step1,
        ]);
    }

    public function storeStep1(StoreRentalStep1Request $request, Service $service)
    {
        $this->guardRentalService($service);

        $sessionKey = "rental_booking.{$service->id}";

        // Release existing hold if user is changing dates/quantity
        $this->releaseExistingHold($sessionKey);

        $start = Carbon::parse($request->validated('start_date'));
        $end = Carbon::parse($request->validated('end_date'));
        $quantity = (int) $request->validated('quantity');

        try {
            $rental = $this->availabilityService->createHold(
                $service, $start, $end, $quantity, auth()->id()
            );
        } catch (RentalUnavailableException $e) {
            return redirect()->route('rental.step1', $service)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        session()->put("{$sessionKey}.rental_id", $rental->id);
        session()->put("{$sessionKey}.step1", $request->validated());

        return redirect()->route('rental.step2', $service);
    }

    public function showStep2(Service $service)
    {
        $this->guardRentalService($service);

        $rental = $this->getActiveHold($service);
        if (! $rental) {
            return $this->holdExpiredRedirect($service);
        }

        $sessionKey = "rental_booking.{$service->id}";

        $defaults = [];
        if ($user = auth()->user()) {
            $defaults = [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ];
        }

        return view('rental.step2', [
            'service' => $service,
            'rental' => $rental,
            'step1' => session("{$sessionKey}.step1"),
            'step2' => session("{$sessionKey}.step2", $defaults),
            'holdExpiresAt' => $rental->held_until->toIso8601String(),
        ]);
    }

    public function storeStep2(StoreRentalStep2Request $request, Service $service)
    {
        $this->guardRentalService($service);

        $rental = $this->getActiveHold($service);
        if (! $rental) {
            return $this->holdExpiredRedirect($service);
        }

        $sessionKey = "rental_booking.{$service->id}";
        session()->put("{$sessionKey}.step2", $request->validated());

        return redirect()->route('rental.step3', $service);
    }

    public function showStep3(Service $service)
    {
        $this->guardRentalService($service);

        $rental = $this->getActiveHold($service);
        if (! $rental) {
            return $this->holdExpiredRedirect($service);
        }

        $sessionKey = "rental_booking.{$service->id}";
        $step1 = session("{$sessionKey}.step1");
        $step2 = session("{$sessionKey}.step2");

        if (! $step1 || ! $step2) {
            return redirect()->route('rental.step1', $service);
        }

        $startDate = Carbon::parse($step1['start_date']);
        $endDate = Carbon::parse($step1['end_date']);
        $durationDays = (int) $startDate->diffInDays($endDate) + 1;

        return view('rental.step3', [
            'service' => $service,
            'rental' => $rental,
            'step1' => $step1,
            'step2' => $step2,
            'durationDays' => $durationDays,
            'holdExpiresAt' => $rental->held_until->toIso8601String(),
        ]);
    }

    public function confirm(Request $request, Service $service)
    {
        $this->guardRentalService($service);

        $rental = $this->getActiveHold($service);
        if (! $rental) {
            return $this->holdExpiredRedirect($service);
        }

        $sessionKey = "rental_booking.{$service->id}";
        $step2 = session("{$sessionKey}.step2");

        if (! $step2) {
            return redirect()->route('rental.step1', $service);
        }

        try {
            $rental = $this->availabilityService->confirmHold($rental, $step2);
        } catch (RentalUnavailableException $e) {
            session()->forget($sessionKey);

            return redirect()->route('rental.step1', $service)
                ->with('error', $e->getMessage());
        }

        session()->forget($sessionKey);
        session()->flash('rental_id', $rental->id);

        return redirect()->route('rental.confirmation', $service);
    }

    public function showConfirmation(Service $service)
    {
        $this->guardRentalService($service);

        $rentalId = session('rental_id');
        $rental = $rentalId ? Rental::find($rentalId) : null;

        return view('rental.confirmation', [
            'service' => $service,
            'rental' => $rental,
        ]);
    }

    public function checkAvailability(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->service_type === ServiceType::ItemRental, 404);

        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $available = $this->availabilityService->getAvailableQuantity($service, $start, $end);

        return response()->json([
            'available_quantity' => $available,
            'total_quantity' => $service->quantity_total,
        ]);
    }

    public function monthlyAvailability(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->service_type === ServiceType::ItemRental, 404);

        $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2030'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $data = $this->availabilityService->getMonthlyAvailability(
            $service, (int) $request->year, (int) $request->month
        );

        return response()->json($data);
    }

    // ───────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────

    private function guardRentalService(Service $service): void
    {
        abort_unless($service->service_type === ServiceType::ItemRental, 404);
        abort_unless($service->is_active, 404);
    }

    private function getActiveHold(Service $service): ?Rental
    {
        $sessionKey = "rental_booking.{$service->id}";
        $rentalId = session("{$sessionKey}.rental_id");

        if (! $rentalId) {
            return null;
        }

        $rental = Rental::find($rentalId);

        if (! $rental || $rental->status !== RentalStatus::Held) {
            session()->forget($sessionKey);

            return null;
        }

        if ($rental->held_until?->isPast()) {
            $rental->update(['status' => RentalStatus::Expired]);
            session()->forget($sessionKey);

            return null;
        }

        return $rental;
    }

    private function releaseExistingHold(string $sessionKey): void
    {
        $rentalId = session("{$sessionKey}.rental_id");
        if (! $rentalId) {
            return;
        }

        $rental = Rental::find($rentalId);
        if ($rental && $rental->status === RentalStatus::Held) {
            $rental->update(['status' => RentalStatus::Expired]);
        }

        session()->forget($sessionKey);
    }

    private function holdExpiredRedirect(Service $service)
    {
        return redirect()->route('rental.step1', $service)
            ->with('error', 'Twoja rezerwacja wygasła. Wybierz termin ponownie.');
    }
}
