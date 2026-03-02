<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add columns without unique constraint on slug
        Schema::table('services', function (Blueprint $table) {
            // Content fields
            $table->string('slug')->nullable()->after('name');
            $table->text('excerpt')->nullable()->after('description');
            $table->text('body')->nullable()->after('excerpt');
            $table->json('content')->nullable()->after('body');

            // SEO fields
            $table->string('meta_title')->nullable()->after('content');
            $table->text('meta_description')->nullable()->after('meta_title');
            $table->string('featured_image')->nullable()->after('meta_description');

            // Publishing workflow
            $table->timestamp('published_at')->nullable()->index()->after('featured_image');

            // P0: Schema.org structured data (web research finding)
            $table->decimal('price_from', 10, 2)->nullable()->after('price');
            $table->string('area_served')->nullable()->after('published_at');
        });

        // Step 2: Data migration - Generate slugs for existing services
        DB::table('services')->get()->each(function ($service) {
            DB::table('services')->where('id', $service->id)->update([
                'slug' => Str::slug($service->name),
                'published_at' => now(), // All existing services published
                'area_served' => 'Poznań', // Default area for local SEO
            ]);
        });

        // Step 3: Add unique constraint AFTER populating data
        Schema::table('services', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'excerpt',
                'body',
                'content',
                'meta_title',
                'meta_description',
                'featured_image',
                'published_at',
                'price_from',
                'area_served',
            ]);
        });
    }
};
