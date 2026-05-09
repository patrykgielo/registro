<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
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
}
