<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that need organization_id for tenant isolation.
     * All start as nullable to support migration of existing data.
     */
    private array $tenantTables = [
        'services',
        'appointments',
        'staff_schedules',
        'staff_date_exceptions',
        'staff_vacation_periods',
        'settings',
        'pages',
        'posts',
        'promotions',
        'portfolio_items',
        'categories',
        'email_templates',
        'email_sends',
        'email_events',
        'sms_templates',
        'sms_sends',
        'sms_events',
        'reminder_configs',
        'service_areas',
        'service_area_waitlists',
        'audit_logs',
        'user_vehicles',
        'user_addresses',
    ];

    public function up(): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('organization_id')
                        ->nullable()
                        ->after('id')
                        ->constrained()
                        ->nullOnDelete();

                    $table->index('organization_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tenantTables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('organization_id');
                });
            }
        }
    }
};
