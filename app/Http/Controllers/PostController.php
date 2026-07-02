<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Support\Seo\MetaTagBuilder;

class PostController extends Controller
{
    /**
     * Display the specified post.
     *
     * Uses PageLayout enum to dynamically select the appropriate layout component.
     */
    public function show(string $slug): \Illuminate\View\View
    {
        $post = Post::where('slug', $slug)
            ->published()
            ->with('category')
            ->firstOrFail();

        // Related posts for sidebar widget (same category, excluding current)
        $relatedPosts = $post->category_id
            ? Post::published()
                ->where('category_id', $post->category_id)
                ->where('id', '!=', $post->id)
                ->latest('published_at')
                ->take(3)
                ->get()
            : collect();

        return view('posts.show', [
            'post' => $post,
            'layout' => $post->layout,
            'relatedPosts' => $relatedPosts,
            ...MetaTagBuilder::forModel($post),
        ]);
    }

    /**
     * Display the paginated archive of published posts in the given category.
     */
    public function category(Category $category): \Illuminate\View\View
    {
        abort_unless($category->type === 'post', 404);

        $items = Post::published()
            ->inCategory($category->id)
            ->latest('published_at')
            ->paginate(9);

        $allCategories = Category::postCategories()->get();

        return view('posts.category', [
            'category' => $category,
            'items' => $items,
            'allCategories' => $allCategories,
            ...MetaTagBuilder::forModel($category),
        ]);
    }
}
