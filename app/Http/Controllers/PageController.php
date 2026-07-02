<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Support\Seo\MetaTagBuilder;

class PageController extends Controller
{
    /**
     * Display the specified page.
     *
     * Uses PageLayout enum to dynamically select the appropriate layout component.
     * The layout field determines presentation: default, full-width, minimal, or home.
     */
    public function show(string $slug): \Illuminate\View\View
    {
        $page = Page::where('slug', $slug)
            ->published()
            ->firstOrFail();

        // Recent posts for sidebar widget
        $recentPosts = Post::published()
            ->latest('published_at')
            ->take(5)
            ->get();

        return view('pages.show', [
            'page' => $page,
            'layout' => $page->layout,
            'recentPosts' => $recentPosts,
            ...MetaTagBuilder::forModel($page),
        ]);
    }
}
