<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->string('currency', 3)->default('PLN');
            $table->string('period_month', 7); // format "2026-05"
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['organization_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payments');
    }
};
