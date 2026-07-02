<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Support\Seo\MetaTagBuilder;

class PortfolioController extends Controller
{
    public function show(string $slug)
    {
        $portfolioItem = PortfolioItem::where('slug', $slug)
            ->where('published_at', '<=', now())
            ->with('category')
            ->firstOrFail();

        return view('portfolio.show', [
            'portfolioItem' => $portfolioItem,
            ...MetaTagBuilder::forModel($portfolioItem),
        ]);
    }

    /**
     * Display the paginated archive of published portfolio items in the given category.
     */
    public function category(Category $category): \Illuminate\View\View
    {
        abort_unless($category->type === 'portfolio', 404);

        $items = PortfolioItem::published()
            ->inCategory($category->id)
            ->latest('published_at')
            ->paginate(9);

        $allCategories = Category::portfolioCategories()->get();

        return view('portfolio.category', [
            'category' => $category,
            'items' => $items,
            'allCategories' => $allCategories,
            ...MetaTagBuilder::forModel($category),
        ]);
    }
}
