<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of `catalog:import` runs (app/docs/features/catalog-json-importer.md).
 *
 * `catalog_import_items` is the ONLY thing `catalog:import-revert` is allowed to
 * delete by: one row per entity a batch created (or merely reused — origin
 * 'reused', never deleted). Deliberately NOT BelongsToOrganization-scoped and
 * carrying no FK to the entities it points at (entity_id is a plain integer):
 * the ledger must outlive a product an admin deleted, and the revert verifies
 * organization_id/name/slug of every pointed-at row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_filename')->nullable();
            $table->string('source_sha256', 64);
            $table->string('status', 32)->default('completed');
            $table->json('summary')->nullable();
            $table->string('actor')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('catalog_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('catalog_import_batches')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // rental_category | service | service_unit | service_location_stock | file
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            // The owning service's id for unit / stock / file rows (no FK on purpose).
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('origin', 16)->default('created');
            $table->string('external_key', 100)->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('path')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'entity_type']);
            $table->index(['organization_id', 'entity_type', 'external_key'], 'catalog_import_items_org_type_key_index');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_items');
        Schema::dropIfExists('catalog_import_batches');
    }
};
