<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make organization_id nullable (needed for events from public pages pre-tenant-resolve)
        // SQLite does not support ALTER COLUMN — recreate via separate logic
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('analytics_events', function (Blueprint $table): void {
                // Drop FK first so we can modify the column
                $table->dropForeign(['organization_id']);
                $table->unsignedBigInteger('organization_id')->nullable()->change();
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->nullOnDelete();
            });
        }

        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('utm_source', 255)->nullable()->after('ip_hash');
            $table->string('utm_medium', 255)->nullable()->after('utm_source');
            $table->string('utm_campaign', 255)->nullable()->after('utm_medium');
            $table->string('referrer_domain', 255)->nullable()->after('utm_campaign');

            // Funnel queries: event distribution per org
            $table->index(['organization_id', 'event'], 'idx_org_event');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropIndex('idx_org_event');
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'referrer_domain']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('analytics_events', function (Blueprint $table): void {
                $table->dropForeign(['organization_id']);
                $table->unsignedBigInteger('organization_id')->nullable(false)->change();
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->cascadeOnDelete();
            });
        }
    }
};
