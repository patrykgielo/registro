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
        Schema::table('services', function (Blueprint $table) {
            // Social proof
            $table->decimal('average_rating', 2, 1)->default(0)->after('icon')
                ->comment('Average rating 0-5 (e.g., 4.9)');
            $table->integer('total_reviews')->default(0)->after('average_rating')
                ->comment('Total number of reviews');

            // Popularity
            $table->boolean('is_popular')->default(false)->after('total_reviews')
                ->comment('Show "Najpopularniejsze" badge');

            // Urgency
            $table->integer('booking_count_week')->default(0)->after('is_popular')
                ->comment('Bookings this week for urgency message');

            // Features list
            $table->json('features')->nullable()->after('booking_count_week')
                ->comment('3-4 bullet points for "What\'s Included" (JSON array)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'average_rating',
                'total_reviews',
                'is_popular',
                'booking_count_week',
                'features',
            ]);
        });
    }
};
