<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable "has this stack been provisioned" marker, consumed by
 * registro:tenant-provision / registro:tenant-provisioned.
 *
 * Deliberately NOT "does organizations have a row": the global lookup seeders
 * (roles/permissions, settings, e-mail templates) must run BEFORE the organization
 * exists, and this marker must stay true even if a pre-provisioning database dump
 * is restored onto an already-live stack — an organizations row can't express
 * that (a restore would bring one back with it, silently re-arming the seeders and
 * blowing away any customization the tenant made to their e-mail templates since).
 *
 * No FK on organization_id — intentionally decoupled from the organization's own
 * lifecycle (soft-delete/purge of the org must not make this table forget the
 * stack was provisioned; there is no scenario where re-seeding roles/templates
 * after that is desirable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provisioning_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('slug')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provisioning_state');
    }
};
