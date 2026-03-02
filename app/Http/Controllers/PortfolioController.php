<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;

class PortfolioController extends Controller
{
    public function show(string $slug)
    {
        $portfolioItem = PortfolioItem::where('slug', $slug)
            ->where('published_at', '<=', now())
            ->with('category')
            ->firstOrFail();

        return view('portfolio.show', compact('portfolioItem'));
    }
}
