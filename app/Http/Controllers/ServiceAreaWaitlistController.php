<?php

namespace App\Http\Controllers;

use App\Models\ServiceAreaWaitlist;
use App\Services\ServiceAreaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceAreaWaitlistController extends Controller
{
    public function __construct(
        private ServiceAreaValidator $areaValidator
    ) {}

    /**
     * Submit waitlist entry for location outside service area
     *
     * POST /api/service-area/waitlist
     * Rate limit: 3 requests per minute per IP
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email:rfc,dns|max:255',
            'name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'place_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify location is actually outside service area (prevent abuse)
        $validationResult = $this->areaValidator->validate(
            $request->input('latitude'),
            $request->input('longitude')
        );

        if ($validationResult['valid']) {
            return response()->json([
                'success' => false,
                'message' => __('booking.service_area.already_available'),
            ], 400);
        }

        try {
            $waitlistEntry = ServiceAreaWaitlist::create([
                'email' => $request->input('email'),
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'requested_address' => $request->input('address'),
                'requested_latitude' => $request->input('latitude'),
                'requested_longitude' => $request->input('longitude'),
                'requested_place_id' => $request->input('place_id'),
                'distance_to_nearest_area_km' => $validationResult['nearest']['distance_km'] ?? null,
                'nearest_area_city' => $validationResult['nearest']['city'] ?? null,
                'session_id' => session()->getId(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => __('booking.service_area.waitlist_success'),
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation - already on waitlist
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => __('booking.service_area.waitlist_duplicate'),
                ], 409);
            }

            throw $e;
        }
    }
}
