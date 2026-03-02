<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;

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
        ]);
    }
}
