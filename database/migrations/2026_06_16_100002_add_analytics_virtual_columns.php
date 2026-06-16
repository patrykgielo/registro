<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE analytics_events
                ADD COLUMN _product_slug VARCHAR(100)
                    GENERATED ALWAYS AS (properties->>'$.service_slug') VIRTUAL,
                ADD COLUMN _cart_id VARCHAR(64)
                    GENERATED ALWAYS AS (properties->>'$.cart_id') VIRTUAL,
                ADD COLUMN _order_id VARCHAR(64)
                    GENERATED ALWAYS AS (properties->>'$.order_id') VIRTUAL,
                ADD COLUMN _revenue DECIMAL(10,2)
                    GENERATED ALWAYS AS (
                        CAST(NULLIF(properties->>'$.total', '') AS DECIMAL(10,2))
                    ) VIRTUAL
            ");
            DB::statement('ALTER TABLE analytics_events ADD INDEX ae_product_slug (_product_slug)');
            DB::statement('ALTER TABLE analytics_events ADD INDEX ae_cart_id (_cart_id)');
            DB::statement('ALTER TABLE analytics_events ADD INDEX ae_order_id (_order_id)');
            DB::statement('ALTER TABLE analytics_events ADD INDEX ae_revenue (_revenue)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE analytics_events DROP COLUMN _product_slug, DROP COLUMN _cart_id, DROP COLUMN _order_id, DROP COLUMN _revenue');
        }
    }
};
