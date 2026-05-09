<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Order identification
            $table->string('order_number', 32)->unique()->notNull();
            $table->string('status', 32)->default('pending_payment')->notNull();

            // Financials
            $table->char('currency', 3)->default('PLN')->notNull();
            $table->decimal('subtotal', 10, 2)->notNull();
            $table->decimal('discount_amount', 10, 2)->default(0)->notNull();
            $table->decimal('tax_amount', 10, 2)->default(0)->notNull();
            $table->decimal('total_amount', 10, 2)->notNull();

            // Customer data
            $table->string('customer_email')->notNull();
            $table->string('customer_first_name')->notNull();
            $table->string('customer_last_name')->notNull();
            $table->string('customer_phone', 50)->nullable();

            // Invoice data
            $table->boolean('invoice_requested')->default(false)->notNull();
            $table->string('invoice_company_name')->nullable();
            $table->string('invoice_nip', 20)->nullable();
            $table->string('invoice_street')->nullable();
            $table->string('invoice_street_number', 20)->nullable();
            $table->string('invoice_postal_code', 10)->nullable();
            $table->string('invoice_city', 100)->nullable();

            // Przelewy24 payment data
            $table->string('p24_session_id')->unique()->nullable();
            $table->string('p24_order_id')->nullable();
            $table->string('p24_token')->nullable();
            $table->integer('p24_amount')->unsigned()->nullable(); // grosze

            // Lifecycle timestamps
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Relations
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();

            // Metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'status']);
            $table->index('expires_at');
            // order_number UNIQUE constraint creates index automatically
            // p24_session_id UNIQUE constraint creates index automatically
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
