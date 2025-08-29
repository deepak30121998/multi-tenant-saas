<?php

namespace App\Http\Controllers\Setup;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SetupController extends Controller
{
    public function showSetup()
    {
        // Check if system is already setup
        if ($this->isSystemSetup()) {
            abort(404, 'System already configured');
        }

        return view('setup.super-admin');
    }

    public function createSuperAdmin(Request $request)
    {
        // Verify system isn't already setup
        if ($this->isSystemSetup()) {
            return response()->json(['error' => 'System already configured'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'setup_key' => 'required|string', // Security key from .env
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify setup key
        if ($request->setup_key !== config('app.setup_key')) {
            return response()->json(['error' => 'Invalid setup key'], 403);
        }

        try {
            \DB::transaction(function () use ($request) {
                // Create system tenant
                $systemTenant = $this->createSystemTenant();

                // Create super admin
                $superAdmin = User::create([
                    'tenant_id' => $systemTenant->id,
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'email_verified_at' => now(),
                    'status' => 'active',
                    'role' => 'super_admin',
                ]);

                // Setup permissions
                $this->setupPermissionsAndRoles($superAdmin);

                // Mark system as setup
                Cache::forever('system_setup_complete', true);
            });

            return response()->json([
                'success' => true,
                'message' => 'Super Admin created successfully',
                'redirect' => route('admin.login')
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Setup failed: ' . $e->getMessage()], 500);
        }
    }

    private function isSystemSetup(): bool
    {
        return Cache::get('system_setup_complete', false) || 
               User::where('role', 'super_admin')->exists();
    }

    private function createSystemTenant(): Tenant
    {
        return Tenant::create([
            'id' => 'system-' . uniqid(),
            'name' => 'System Administration',
            'slug' => 'system',
            'primary_domain' => config('app.url'),
            'database' => config('database.connections.mysql.database'),
            'status' => 'active',
            'plan' => 'unlimited',
            'activated_at' => now(),
            'settings' => ['is_system_tenant' => true],
        ]);
    }

    private function setupPermissionsAndRoles(User $superAdmin): void
    {
        // Implementation similar to command above
        // Create roles, permissions, and assign to super admin
    }
}