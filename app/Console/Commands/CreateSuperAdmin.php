<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CreateSuperAdmin extends Command
{
    protected $signature = 'superadmin:create 
                           {--email= : Super admin email}
                           {--password= : Super admin password}
                           {--name= : Super admin name}
                           {--force : Force creation even if super admin exists}';

    protected $description = 'Create a Super Admin user for central management';

    public function handle()
    {
        // Check if super admin already exists
        if (!$this->option('force') && $this->superAdminExists()) {
            $this->error('Super Admin already exists. Use --force to override.');
            return 1;
        }

        $email = $this->option('email') ?: $this->ask('Enter Super Admin email');
        $name = $this->option('name') ?: $this->ask('Enter Super Admin name');
        $password = $this->option('password') ?: $this->secret('Enter Super Admin password');

        // If forcing and user exists, validate excluding existing user
        $emailRule = 'required|email';
        if (!$this->option('force')) {
            $emailRule .= '|unique:users,email';
        }

        // Validate input
        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => $emailRule,
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return 1;
        }

        try {
            // Create the master/system tenant if it doesn't exist
            $systemTenant = $this->createSystemTenant();

            // If forcing, update existing user or create new one
            if ($this->option('force')) {
                $superAdmin = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'tenant_id' => $systemTenant ? $systemTenant->id : null,
                        'name' => $name,
                        'password' => Hash::make($password),
                        'email_verified_at' => now(),
                        'status' => 'active',
                        'is_super_admin' => true,
                    ]
                );
            } else {
                $superAdmin = User::create([
                    'tenant_id' => $systemTenant ? $systemTenant->id : null,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'status' => 'active',
                    'is_super_admin' => true,
                ]);
            }

            // Create and assign super admin role with all permissions
            $this->createSuperAdminRoleAndPermissions($superAdmin);

            $this->info("Super Admin created successfully!");
            $this->info("Email: {$email}");
            $this->info("Login URL: " . config('app.url') . "/admin/login");

        } catch (\Exception $e) {
            $this->error("Failed to create Super Admin: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function superAdminExists(): bool
    {
        return User::where('is_super_admin', true)
                  ->orWhereHas('roles', function ($query) {
                      $query->where('name', 'super_admin');
                  })
                  ->exists();
    }

    private function createSystemTenant(): ?Tenant
    {
        try {
            // First, let's try to find existing system tenant
            $tenant = Tenant::find('system-tenant-001');
            if ($tenant) {
                return $tenant;
            }

            // Create using raw insert to avoid Stancl model complications
            $tenantData = [
                'id' => 'system-tenant-001',
                'created_at' => now(),
                'updated_at' => now(),
                'primary_domain' => 'system.localhost',
                'database' => null, // System tenant doesn't need separate DB
                'name' => 'System Administration',
                'slug' => 'system', 
                'admin_email' => config('mail.from.address', 'admin@example.com'),
                'admin_name' => 'System Administrator',
                'status' => 'active',
                'plan' => 'unlimited',
                'activated_at' => now(),
            ];

            // Add optional fields if they exist in your table structure
            $optionalFields = [
                'db_host' => config('database.connections.mysql.host', 'localhost'),
                'db_port' => config('database.connections.mysql.port', 3306),
                'db_username' => config('database.connections.mysql.username', 'root'),
                'db_password' => config('database.connections.mysql.password', ''),
                'domains' => json_encode(['localhost']),
                'features' => json_encode([
                    'unlimited_users' => true,
                    'unlimited_storage' => true,
                    'all_modules' => true
                ]),
                'limits' => json_encode([
                    'users' => -1, // -1 for unlimited
                    'storage' => -1,
                    'api_calls' => -1
                ]),
                'settings' => json_encode([
                    'timezone' => config('app.timezone', 'UTC'),
                    'locale' => config('app.locale', 'en')
                ])
            ];
            
            // Only add fields that exist in the table
            foreach ($optionalFields as $field => $value) {
                $tenantData[$field] = $value;
            }

            // Insert directly to avoid model constraints
            DB::connection('central')->table('tenants')->insert($tenantData);
            
            $this->info("System tenant created successfully (using central database).");
            return Tenant::find('system-tenant-001');
            
        } catch (\Exception $e) {
            $this->warn("Could not create system tenant: " . $e->getMessage());
            $this->warn("Creating user without tenant association...");
            return null;
        }
    }

    private function createSuperAdminRoleAndPermissions(User $superAdmin): void
    {
        // Create comprehensive permissions for super admin
        $permissions = [
            // Tenant Management
            'tenants.view', 'tenants.create', 'tenants.edit', 'tenants.delete',
            'tenants.suspend', 'tenants.activate', 'tenants.impersonate',
            
            // User Management
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'users.impersonate', 'users.suspend', 'users.activate',
            
            // System Management
            'system.settings', 'system.maintenance', 'system.logs',
            'system.cache', 'system.queue', 'system.backup',
            
            // Analytics & Reporting
            'analytics.view', 'reports.generate', 'billing.view',
            
            // Security
            'security.audit', 'security.permissions', 'security.roles',
        ];

        // Create permissions if they don't exist
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web'
            ]);
        }

        // Create super admin role
        $superAdminRole = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web'
        ]);

        // Assign all permissions to super admin role
        $superAdminRole->syncPermissions(Permission::all());

        // Remove existing roles and assign super admin role
        $superAdmin->syncRoles([$superAdminRole]);

        $this->info("Created " . count($permissions) . " permissions and assigned super_admin role.");
    }
}