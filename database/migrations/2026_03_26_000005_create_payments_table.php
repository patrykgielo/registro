<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Przelewy24 identifiers
            $table->string('p24_session_id')->notNull();
            $table->string('p24_order_id')->nullable();

            // Financials
            $table->unsignedInteger('amount')->notNull(); // grosze
            $table->char('currency', 3)->default('PLN')->notNull();

            // Status
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending')->notNull();

            // Webhook & verification
            $table->json('webhook_payload')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('order_id');
            $table->index('p24_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
