<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Only run in development
        if (!app()->environment(['local', 'development', 'testing'])) {
            $this->command->warn('Super Admin seeder only runs in development environments');
            return;
        }

        // Create system tenant
        $systemTenant = Tenant::firstOrCreate(
            ['slug' => 'system'],
            [
                'id' => 'system-' . uniqid(),
                'name' => 'System Administration',
                'primary_domain' => 'admin.' . parse_url(config('app.url'), PHP_URL_HOST),
                'database' => config('database.connections.mysql.database'),
                'status' => 'active',
                'plan' => 'unlimited',
                'activated_at' => now(),
            ]
        );

        // Create super admin user
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'tenant_id' => $systemTenant->id,
                'name' => 'Super Administrator',
                'password' => Hash::make('SuperAdmin123!'),
                'email_verified_at' => now(),
                'status' => 'active',
                'role' => 'super_admin',
            ]
        );

        // Create permissions and roles
        $this->createPermissionsAndRoles($superAdmin);

        $this->command->info('Super Admin created: superadmin@example.com / SuperAdmin123!');
    }

    private function createPermissionsAndRoles(User $superAdmin): void
    {
        // Create super admin role with all permissions
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        
        // Create basic permissions (expand as needed)
        $permissions = [
            'manage_tenants', 'manage_users', 'manage_system',
            'view_analytics', 'manage_billing', 'manage_security'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role->syncPermissions(Permission::all());
        $superAdmin->assignRole($role);
    }
}