<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert global single-column unique constraints to composite (organization_id + column)
     * on all tenant-scoped tables. Global uniques break multi-tenant onboarding when
     * a vertical seeder inserts records with names/slugs already used by another tenant.
     *
     * SKIPPED: email_templates + sms_templates — those tables contain globally-shared
     * NULL-org system templates. MySQL treats NULL as distinct in unique indexes, so a
     * composite (organization_id, key, language) unique would allow duplicate global
     * templates on re-seed. Keep their current (key, language) global unique intact.
     */
    public function up(): void
    {
        // services: name and slug
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_name_unique');
            $table->unique(['organization_id', 'name'], 'services_org_name_unique');

            $table->dropUnique('services_slug_unique');
            $table->unique(['organization_id', 'slug'], 'services_org_slug_unique');
        });

        // categories: slug
        // NOTE: organization_id is nullable on categories (NULL = global/system category).
        // MySQL treats each NULL as distinct in a unique index, so two NULL-org rows with the
        // same slug ARE allowed by the composite unique — same behaviour as the old global unique.
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_slug_unique');
            $table->unique(['organization_id', 'slug'], 'categories_org_slug_unique');
        });

        // pages: slug
        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique('pages_slug_unique');
            $table->unique(['organization_id', 'slug'], 'pages_org_slug_unique');
        });

        // posts: slug
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique('posts_slug_unique');
            $table->unique(['organization_id', 'slug'], 'posts_org_slug_unique');
        });

        // portfolio_items: slug
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropUnique('portfolio_items_slug_unique');
            $table->unique(['organization_id', 'slug'], 'portfolio_items_org_slug_unique');
        });

        // promotions: slug
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique('promotions_slug_unique');
            $table->unique(['organization_id', 'slug'], 'promotions_org_slug_unique');
        });

        // service_areas: spatial center + radius uniqueness
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropUnique('unique_service_area');
            $table->unique(['organization_id', 'latitude', 'longitude', 'radius_km'], 'service_areas_org_coords_unique');
        });

        // orders: order_number (must be unique per org, not globally)
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_order_number_unique');
            $table->unique(['organization_id', 'order_number'], 'orders_org_order_number_unique');
        });
    }

    public function down(): void
    {
        // Cannot roll back: after up() runs in production, different tenants may share the
        // same name/slug values (which the composite unique allows). Restoring the old global
        // single-column uniques would fail with SQLSTATE[23000] Duplicate entry on those rows.
        throw new \RuntimeException(
            'Cannot roll back: data may contain cross-tenant duplicates on the restored global unique constraints.'
        );
    }
};
