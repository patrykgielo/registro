<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Promotion;

class PromotionController extends Controller
{
    /**
     * Display the specified promotion.
     *
     * Uses PageLayout enum to dynamically select the appropriate layout component.
     * Only shows active and valid promotions.
     */
    public function show(string $slug): \Illuminate\View\View
    {
        $promotion = Promotion::where('slug', $slug)
            ->activeAndValid()
            ->firstOrFail();

        // Recent posts for sidebar widget
        $recentPosts = Post::published()
            ->latest('published_at')
            ->take(5)
            ->get();

        return view('promotions.show', [
            'promotion' => $promotion,
            'layout' => $promotion->layout,
            'recentPosts' => $recentPosts,
        ]);
    }
}
