<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('anonymous_id', 64)->nullable()->after('session_id');
            $table->string('browser', 100)->nullable()->after('device_type');
            $table->string('os', 100)->nullable()->after('browser');
            $table->index(['organization_id', 'utm_source'], 'ae_org_utm');
            $table->index('anonymous_id', 'ae_anon_id');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropIndex('ae_anon_id');
            $table->dropIndex('ae_org_utm');
            $table->dropColumn(['anonymous_id', 'browser', 'os']);
        });
    }
};
