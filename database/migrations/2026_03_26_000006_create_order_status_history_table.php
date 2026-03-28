<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // State transition: null = initial state creation
            $table->string('from', 32)->nullable();
            $table->string('to', 32)->notNull();

            // Responsible actor (polymorphic — User, System, etc.)
            $table->string('responsible_type', 100)->nullable();
            $table->bigInteger('responsible_id')->unsigned()->nullable();

            // Arbitrary metadata (reason, notes, external reference, etc.)
            $table->json('properties')->nullable();

            // Immutable history — no updated_at
            $table->timestamp('created_at')->notNull()->useCurrent();

            $table->index('order_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
