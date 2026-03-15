<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rental_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('pricing_unit', ['hourly', 'daily', 'weekly'])->default('daily');
            $table->decimal('unit_price_at_booking', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'confirmed', 'active', 'returned', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Contact info snapshot
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Invoice data
            $table->boolean('invoice_requested')->default(false);
            $table->string('invoice_company_name')->nullable();
            $table->string('invoice_nip', 20)->nullable();
            $table->string('invoice_street')->nullable();
            $table->string('invoice_street_number', 20)->nullable();
            $table->string('invoice_postal_code', 10)->nullable();
            $table->string('invoice_city')->nullable();

            // Status timestamps
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['rental_item_id', 'start_date', 'end_date']);
            $table->index(['customer_id', 'start_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
