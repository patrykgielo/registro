<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create a default organization and assign all existing data to it.
     * This enables a smooth transition from single-tenant to multi-tenant.
     */
    public function up(): void
    {
        // Find the first super-admin user, or first user as fallback
        $owner = DB::table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'super-admin')
            ->select('users.id')
            ->first();

        if (! $owner) {
            $owner = DB::table('users')->first();
        }

        // If no users exist (fresh install), skip seeding
        if (! $owner) {
            return;
        }

        // Create default organization
        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Default Organization',
            'slug' => 'default',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
            'settings' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign all existing staff/admin users to the default organization
        $staffUsers = DB::table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['super-admin', 'admin', 'staff'])
            ->select('users.id', 'roles.name as role_name')
            ->distinct()
            ->get();

        foreach ($staffUsers as $user) {
            $pivotRole = match ($user->role_name) {
                'super-admin' => 'owner',
                'admin' => 'admin',
                default => 'staff',
            };

            DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $orgId,
                'user_id' => $user->id,
                'role' => $pivotRole,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update all tenant tables to belong to default organization
        $tenantTables = [
            'services', 'appointments', 'staff_schedules', 'staff_date_exceptions',
            'staff_vacation_periods', 'settings', 'pages', 'posts', 'promotions',
            'portfolio_items', 'categories', 'email_templates', 'email_sends',
            'email_events', 'sms_templates', 'sms_sends', 'sms_events',
            'reminder_configs', 'service_areas', 'service_area_waitlists',
            'audit_logs', 'user_vehicles', 'user_addresses',
        ];

        foreach ($tenantTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id')) {
                DB::table($table)
                    ->whereNull('organization_id')
                    ->update(['organization_id' => $orgId]);
            }
        }
    }

    public function down(): void
    {
        // Remove default organization — data stays with null organization_id
        $org = DB::table('organizations')->where('slug', 'default')->first();
        if ($org) {
            // Nullify all references first
            $tenantTables = [
                'services', 'appointments', 'staff_schedules', 'staff_date_exceptions',
                'staff_vacation_periods', 'settings', 'pages', 'posts', 'promotions',
                'portfolio_items', 'categories', 'email_templates', 'email_sends',
                'email_events', 'sms_templates', 'sms_sends', 'sms_events',
                'reminder_configs', 'service_areas', 'service_area_waitlists',
                'audit_logs', 'user_vehicles', 'user_addresses',
            ];

            foreach ($tenantTables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id')) {
                    DB::table($table)
                        ->where('organization_id', $org->id)
                        ->update(['organization_id' => null]);
                }
            }

            DB::table('organization_user')->where('organization_id', $org->id)->delete();
            DB::table('organizations')->where('id', $org->id)->delete();
        }
    }
};
