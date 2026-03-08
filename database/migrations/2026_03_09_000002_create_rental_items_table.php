<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rental_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity_total')->default(1);
            $table->decimal('price_per_day', 10, 2);
            $table->decimal('price_per_hour', 10, 2)->nullable();
            $table->decimal('price_per_week', 10, 2)->nullable();
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->string('featured_image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('specifications')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index('is_active');
            $table->index('rental_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_items');
    }
};
