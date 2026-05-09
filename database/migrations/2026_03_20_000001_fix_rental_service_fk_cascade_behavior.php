<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: service_id FK on rentals was nullable + cascadeOnDelete.
 * This silently deleted active/paid rentals when a Service was deleted.
 *
 * Change: restrictOnDelete (prevent deleting Service with active rentals)
 * + NOT NULL (every rental must have a service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->cascadeOnDelete();
        });
    }
};
