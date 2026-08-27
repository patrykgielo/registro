<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `primary_slot` is an application-managed shadow column (kept in sync by
     * App\Observers\LocationObserver, same pattern as `carts.active_slot` —
     * see 2026_07_05_000001_add_active_slot_unique_to_carts_table.php): `1`
     * for the tenant's main branch, `NULL` for every other one. NULL is never
     * unique-constrained (MySQL and SQLite alike), so the composite unique
     * below guarantees "at most one primary location per organization" at the
     * DB level without blocking a tenant from having many non-primary ones.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('code', 20)->nullable();

            // Address
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();

            // Same shape as service_areas: decimal(10,8)/decimal(11,8).
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('opening_hours')->nullable();

            $table->string('photo')->nullable();
            $table->json('gallery')->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedTinyInteger('primary_slot')->nullable();

            $table->timestamps();

            $table->index(['latitude', 'longitude'], 'locations_coords_index');

            // Tenant-scoped uniques — NEVER bare `slug` (2026_06_29_120000 had to fix
            // exactly this mistake on service_areas; see .claude/rules/migrations.md).
            $table->unique(['organization_id', 'slug'], 'locations_org_slug_unique');
            $table->unique(['organization_id', 'primary_slot'], 'locations_org_primary_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
