<?php

namespace App\Http\Controllers;

use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\View\View;

class RentalController extends Controller
{
    public function index(): View
    {
        $categories = RentalCategory::active()->ordered()
            ->withCount(['services' => fn ($q) => $q->active()])
            ->get();

        $featuredServices = Service::rentable()->active()
            ->ordered()
            ->limit(6)
            ->get();

        return view('rentals.index', compact('categories', 'featuredServices'));
    }

    public function showCategory(RentalCategory $category): View
    {
        abort_unless($category->is_active, 404);

        $services = $category->services()->active()->ordered()->get();
        $allCategories = RentalCategory::active()->ordered()->get();

        return view('rentals.category', compact('category', 'services', 'allCategories'));
    }
}
