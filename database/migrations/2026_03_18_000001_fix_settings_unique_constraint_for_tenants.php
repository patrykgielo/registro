<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix settings unique constraint to include organization_id.
 *
 * Before: unique(group, key) — prevents tenants from having same keys as global settings.
 * After: unique(organization_id, group, key) — each tenant can have its own settings.
 *
 * Incident 2026-03-18: Registration flow crashed with UniqueConstraintViolation
 * because SeedOrganizationDefaults tried to create tenant-scoped settings that
 * conflicted with global settings on the (group, key) unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_group_key_unique');
            $table->unique(['organization_id', 'group', 'key'], 'settings_org_group_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_org_group_key_unique');
            $table->unique(['group', 'key'], 'settings_group_key_unique');
        });
    }
};
