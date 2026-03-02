<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ServiceAreaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceAreaController extends Controller
{
    public function __construct(
        private ServiceAreaValidator $validator
    ) {}

    /**
     * Validate if coordinates are within service area
     *
     * POST /api/service-area/validate
     * Rate limit: 10 requests per minute
     */
    public function validateLocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'valid' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->validator->validate(
            $request->input('latitude'),
            $request->input('longitude')
        );

        return response()->json([
            'valid' => $result['valid'],
            'message' => $result['message'],
            'nearest_area' => $result['nearest'],
            'available_areas' => $result['available_areas'] ?? [],
        ]);
    }

    /**
     * Get service areas for map display (public endpoint)
     *
     * GET /api/service-area/areas
     * Rate limit: 30 requests per minute
     */
    public function getServiceAreas(): JsonResponse
    {
        $areas = $this->validator->getPublicServiceAreas();

        return response()->json([
            'areas' => $areas,
        ]);
    }
}
