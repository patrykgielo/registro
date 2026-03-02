<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleDataController extends Controller
{
    /**
     * Get all active vehicle types
     */
    public function vehicleTypes(): JsonResponse
    {
        $types = VehicleType::active()
            ->ordered()
            ->get(['id', 'name', 'slug', 'description', 'examples']);

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Get car brands (for select dropdown)
     * Returns ALL active brands (no vehicle type filtering)
     */
    public function brands(Request $request): JsonResponse
    {
        // Return all active brands - vehicle type is customer's declaration
        $brands = CarBrand::active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => $brands,
        ]);
    }

    /**
     * Get car models (for select dropdown)
     * Filter by car_brand_id only (no vehicle type filtering)
     */
    public function models(Request $request): JsonResponse
    {
        $request->validate([
            'car_brand_id' => 'required|exists:car_brands,id',
        ]);

        // Return all active models for brand - vehicle type is customer's declaration
        $models = CarModel::active()
            ->where('car_brand_id', $request->car_brand_id)
            ->with('brand:id,name')
            ->orderBy('name')
            ->get(['id', 'car_brand_id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => $models,
        ]);
    }

    /**
     * Get years range for vehicle year dropdown
     */
    public function years(): JsonResponse
    {
        $currentYear = (int) date('Y');
        $startYear = 1990;
        $years = range($currentYear, $startYear);

        return response()->json([
            'success' => true,
            'data' => $years,
        ]);
    }
}
