<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('rental_days');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->json('price_snapshot')->nullable();
            $table->timestamps();

            $table->index('cart_id');
            $table->index('service_id');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT chk_cart_items_end_date CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT chk_cart_items_quantity CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
