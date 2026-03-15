<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed roles and permissions for RBAC (production lookup data).
     *
     * Creates 4 roles with hierarchical permissions:
     * - super-admin: Full system access (all permissions)
     * - admin: Business operations (modules, bookings, content)
     * - staff: Service delivery (appointments, own availability)
     * - customer: Self-service (own bookings)
     *
     * Permissions are module-namespaced (e.g. services.view, bookings.create).
     * This seeder is idempotent - can be run multiple times safely.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions (module-namespaced)
        $permissions = [
            // Settings (core — always available)
            'settings.manage',

            // Services module
            'services.view',
            'services.create',
            'services.edit',
            'services.delete',

            // Bookings module
            'bookings.view',
            'bookings.create',
            'bookings.edit',
            'bookings.delete',
            'bookings.view_own',
            'bookings.cancel_own',

            // Rentals module
            'rentals.view',
            'rentals.create',
            'rentals.edit',
            'rentals.delete',

            // Staff module
            'staff.view',
            'staff.create',
            'staff.edit',
            'staff.delete',
            'staff.manage_availability',
            'staff.view_availability',

            // Customers module
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',

            // Communication module
            'communication.manage_templates',
            'communication.view_logs',
            'communication.view_events',
            'communication.manage_suppressions',

            // Website module
            'website.manage',

            // Vehicles module (lookup tables)
            'vehicles.view',

            // Service area module
            'service_area.manage',

            // User management (super-admin only)
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions

        // Super Admin - all permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions(Permission::all());

        // Admin - business operations (no user management)
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'settings.manage',
            'services.view', 'services.create', 'services.edit', 'services.delete',
            'bookings.view', 'bookings.create', 'bookings.edit', 'bookings.delete',
            'rentals.view', 'rentals.create', 'rentals.edit', 'rentals.delete',
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'staff.view', 'staff.create', 'staff.edit', 'staff.delete',
            'staff.manage_availability', 'staff.view_availability',
            'communication.manage_templates', 'communication.view_logs',
            'website.manage',
            'vehicles.view',
            'service_area.manage',
        ]);

        // Staff - can manage own availability and appointments
        $staff = Role::firstOrCreate(['name' => 'staff']);
        $staff->syncPermissions([
            'services.view',
            'bookings.view', 'bookings.create', 'bookings.edit',
            'staff.manage_availability', 'staff.view_availability',
        ]);

        // Customer - can only view and book appointments
        $customer = Role::firstOrCreate(['name' => 'customer']);
        $customer->syncPermissions([
            'services.view',
            'bookings.view_own',
            'bookings.create',
            'bookings.cancel_own',
        ]);

        // Create default admin user if doesn't exist
        $adminUser = \App\Models\User::where('email', 'admin@example.com')->first();
        if ($adminUser) {
            $adminUser->assignRole('super-admin');
        }
    }
}
