<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['rental_item_id']);
            $table->dropIndex(['rental_item_id', 'start_date', 'end_date']);
            $table->dropColumn('rental_item_id');

            $table->foreignId('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->index(['service_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropIndex(['service_id', 'start_date', 'end_date']);
            $table->dropColumn('service_id');

            $table->foreignId('rental_item_id')->nullable()->constrained('rental_items')->cascadeOnDelete();
            $table->index(['rental_item_id', 'start_date', 'end_date']);
        });
    }
};
