<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->date('original_end_date');
            $table->date('requested_end_date');
            $table->unsignedSmallInteger('additional_days');
            $table->decimal('additional_amount', 10, 2)->default(0);
            $table->text('customer_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'ix_extension_requests_org_status');
            $table->index('order_id', 'ix_extension_requests_order');
            $table->index('order_item_id', 'ix_extension_requests_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_extension_requests');
    }
};
