<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Rename permissions from flat format to module-namespaced format.
     * Safe: model_has_permissions uses FK to permissions.id, so rename preserves associations.
     */
    public function up(): void
    {
        $map = [
            'view users' => 'users.view',
            'create users' => 'users.create',
            'edit users' => 'users.edit',
            'delete users' => 'users.delete',
            'view services' => 'services.view',
            'create services' => 'services.create',
            'edit services' => 'services.edit',
            'delete services' => 'services.delete',
            'view appointments' => 'bookings.view',
            'create appointments' => 'bookings.create',
            'edit appointments' => 'bookings.edit',
            'delete appointments' => 'bookings.delete',
            'view own appointments' => 'bookings.view_own',
            'cancel own appointments' => 'bookings.cancel_own',
            'manage availability' => 'staff.manage_availability',
            'view availability' => 'staff.view_availability',
            'manage email templates' => 'communication.manage_templates',
            'view email logs' => 'communication.view_logs',
            'view email events' => 'communication.view_events',
            'manage suppressions' => 'communication.manage_suppressions',
            'manage settings' => 'settings.manage',
        ];

        foreach ($map as $old => $new) {
            Permission::where('name', $old)->update(['name' => $new]);
        }

        // Clear Spatie cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the renames.
     */
    public function down(): void
    {
        $map = [
            'users.view' => 'view users',
            'users.create' => 'create users',
            'users.edit' => 'edit users',
            'users.delete' => 'delete users',
            'services.view' => 'view services',
            'services.create' => 'create services',
            'services.edit' => 'edit services',
            'services.delete' => 'delete services',
            'bookings.view' => 'view appointments',
            'bookings.create' => 'create appointments',
            'bookings.edit' => 'edit appointments',
            'bookings.delete' => 'delete appointments',
            'bookings.view_own' => 'view own appointments',
            'bookings.cancel_own' => 'cancel own appointments',
            'staff.manage_availability' => 'manage availability',
            'staff.view_availability' => 'view availability',
            'communication.manage_templates' => 'manage email templates',
            'communication.view_logs' => 'view email logs',
            'communication.view_events' => 'view email events',
            'communication.manage_suppressions' => 'manage suppressions',
            'settings.manage' => 'manage settings',
        ];

        foreach ($map as $old => $new) {
            Permission::where('name', $old)->update(['name' => $new]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
