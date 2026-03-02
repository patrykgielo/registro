<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add body column to pages
        Schema::table('pages', function (Blueprint $table) {
            $table->text('body')->nullable()->after('slug');
        });

        // Add body column to posts
        Schema::table('posts', function (Blueprint $table) {
            $table->text('body')->nullable()->after('excerpt');
        });

        // Add body column to promotions
        Schema::table('promotions', function (Blueprint $table) {
            $table->text('body')->nullable()->after('slug');
        });

        // Add body column to portfolio_items
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->text('body')->nullable()->after('slug');
        });

        // Migrate existing Builder content to body field
        $this->migrateExistingContent();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('body');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('body');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('body');
        });

        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }

    /**
     * Migrate existing Builder "text" blocks to body field
     */
    private function migrateExistingContent(): void
    {
        // Migrate Pages
        $pages = DB::table('pages')->whereNotNull('content')->get();
        foreach ($pages as $page) {
            $content = json_decode($page->content, true);
            if (is_array($content) && ! empty($content)) {
                $body = $this->extractTextBlocks($content);
                $remainingContent = $this->removeTextBlocks($content);

                DB::table('pages')
                    ->where('id', $page->id)
                    ->update([
                        'body' => $body,
                        'content' => ! empty($remainingContent) ? json_encode($remainingContent) : null,
                    ]);
            }
        }

        // Migrate Posts
        $posts = DB::table('posts')->whereNotNull('content')->get();
        foreach ($posts as $post) {
            $content = json_decode($post->content, true);
            if (is_array($content) && ! empty($content)) {
                $body = $this->extractTextBlocks($content);
                $remainingContent = $this->removeTextBlocks($content);

                DB::table('posts')
                    ->where('id', $post->id)
                    ->update([
                        'body' => $body,
                        'content' => ! empty($remainingContent) ? json_encode($remainingContent) : null,
                    ]);
            }
        }

        // Migrate Promotions
        $promotions = DB::table('promotions')->whereNotNull('content')->get();
        foreach ($promotions as $promotion) {
            $content = json_decode($promotion->content, true);
            if (is_array($content) && ! empty($content)) {
                $body = $this->extractTextBlocks($content);
                $remainingContent = $this->removeTextBlocks($content);

                DB::table('promotions')
                    ->where('id', $promotion->id)
                    ->update([
                        'body' => $body,
                        'content' => ! empty($remainingContent) ? json_encode($remainingContent) : null,
                    ]);
            }
        }

        // Migrate Portfolio Items
        $portfolioItems = DB::table('portfolio_items')->whereNotNull('content')->get();
        foreach ($portfolioItems as $item) {
            $content = json_decode($item->content, true);
            if (is_array($content) && ! empty($content)) {
                $body = $this->extractTextBlocks($content);
                $remainingContent = $this->removeTextBlocks($content);

                DB::table('portfolio_items')
                    ->where('id', $item->id)
                    ->update([
                        'body' => $body,
                        'content' => ! empty($remainingContent) ? json_encode($remainingContent) : null,
                    ]);
            }
        }
    }

    /**
     * Extract all "text" blocks and concatenate their content
     */
    private function extractTextBlocks(array $content): ?string
    {
        $textBlocks = [];

        foreach ($content as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['data']['content'])) {
                $textBlocks[] = $block['data']['content'];
            }
        }

        return ! empty($textBlocks) ? implode("\n\n", $textBlocks) : null;
    }

    /**
     * Remove all "text" blocks, keep other blocks (gallery, cta, video, quote, etc.)
     */
    private function removeTextBlocks(array $content): array
    {
        return array_filter($content, function ($block) {
            return ! isset($block['type']) || $block['type'] !== 'text';
        });
    }
};
