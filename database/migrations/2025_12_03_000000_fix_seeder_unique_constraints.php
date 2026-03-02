<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CRITICAL: Fixes unique constraints to prevent duplicate records during seeding.
     *
     * Changes:
     * 1. Settings: Replace single 'key' unique with composite 'group+key' unique
     * 2. Services: Add unique constraint on 'name' field
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Drop existing single-column unique constraint
            $table->dropUnique(['key']);

            // Add composite unique constraint (group + key)
            // This allows same key in different groups, prevents duplicates within groups
            $table->unique(['group', 'key'], 'settings_group_key_unique');
        });

        Schema::table('services', function (Blueprint $table) {
            // Add unique constraint on service name
            // Prevents duplicate services with same name but different pricing
            $table->unique('name', 'services_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Restore original single-column unique constraint
            $table->dropUnique('settings_group_key_unique');
            $table->unique('key');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_name_unique');
        });
    }
};
