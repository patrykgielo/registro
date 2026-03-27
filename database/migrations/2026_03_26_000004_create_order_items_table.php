<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();

            // Snapshot nazwy usługi w momencie zamówienia
            $table->string('service_name');

            // Ilość i okres
            $table->unsignedSmallInteger('quantity');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('rental_days');

            // Wycena
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->json('price_snapshot')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('order_id');
            $table->index('service_id');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
