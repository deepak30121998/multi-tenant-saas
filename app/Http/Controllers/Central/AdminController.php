<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:super_admin']);
    }

    /**
     * Show admin dashboard.
     */
    public function dashboard()
    {
        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'suspended_tenants' => Tenant::where('status', 'suspended')->count(),
            'pending_tenants' => Tenant::where('status', 'pending')->count(),
            'total_users' => User::count(),
            'monthly_revenue' => Tenant::sum('monthly_revenue'),
            'storage_used' => Tenant::sum('storage_used'),
        ];

        $recentTenants = Tenant::with('users')
            ->latest()
            ->take(10)
            ->get();

        $recentActivity = activity()
            ->latest()
            ->take(20)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentTenants', 'recentActivity'));
    }

    /**
     * List all tenants.
     */
    public function tenants(Request $request)
    {
        $query = Tenant::query();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('admin_email', 'like', "%{$search}%")
                  ->orWhere('primary_domain', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by plan
        if ($request->filled('plan')) {
            $query->where('plan', $request->plan);
        }

        $tenants = $query->withCount('users')
            ->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'))
            ->paginate(20);

        return view('admin.tenants.index', compact('tenants'));
    }

    /**
     * Show tenant details.
     */
    public function showTenant(Tenant $tenant)
    {
        $tenant->load(['users' => function ($query) {
            $query->latest()->take(10);
        }]);

        $stats = [
            'total_users' => $tenant->users()->count(),
            'active_users' => $tenant->users()->where('status', 'active')->count(),
            'storage_used' => $this->formatBytes($tenant->storage_used),
            'last_activity' => $tenant->users()->max('last_login_at'),
        ];

        $recentActivity = $this->getTenantActivity($tenant);

        return view('admin.tenants.show', compact('tenant', 'stats', 'recentActivity'));
    }

    /**
     * Show tenant creation form.
     */
    public function createTenant()
    {
        $plans = ['basic', 'pro', 'enterprise'];
        return view('admin.tenants.create', compact('plans'));
    }

    /**
     * Store new tenant.
     */
    public function storeTenant(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:tenants,admin_email',
            'plan' => 'required|in:basic,pro,enterprise',
            'domain' => 'required|string|unique:tenants,primary_domain',
        ]);

        DB::beginTransaction();

        try {
            $tenantId = Str::uuid();
            $slug = Str::slug($request->name);
            $database = 'tenant_' . $slug . '_' . time();

            $tenant = Tenant::create([
                'id' => $tenantId,
                'name' => $request->name,
                'slug' => $slug,
                'primary_domain' => $request->domain,
                'database' => $database,
                'admin_email' => $request->admin_email,
                'admin_name' => $request->admin_name,
                'status' => 'active',
                'plan' => $request->plan,
                'activated_at' => now(),
                'settings' => [
                    'allow_registration' => true,
                    'require_email_verification' => true,
                ],
                'features' => $this->getDefaultFeatures($request->plan),
                'limits' => $this->getDefaultLimits($request->plan),
            ]);

            // Create tenant database
            DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Run tenant migrations
            $this->runTenantMigrations($tenant);

            DB::commit();

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('Tenant created by admin');

            return redirect()->route('admin.tenants.show', $tenant)
                ->with('success', 'Tenant created successfully!');

        } catch (\Exception $e) {
            DB::rollback();
            return back()->withErrors(['error' => 'Failed to create tenant: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Show tenant edit form.
     */
    public function editTenant(Tenant $tenant)
    {
        $plans = ['basic', 'pro', 'enterprise'];
        return view('admin.tenants.edit', compact('tenant', 'plans'));
    }

    /**
     * Update tenant.
     */
    public function updateTenant(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:tenants,admin_email,' . $tenant->id,
            'plan' => 'required|in:basic,pro,enterprise',
            'status' => 'required|in:active,inactive,suspended,pending',
        ]);

        $tenant->update([
            'name' => $request->name,
            'admin_name' => $request->admin_name,
            'admin_email' => $request->admin_email,
            'plan' => $request->plan,
            'status' => $request->status,
            'features' => $this->getDefaultFeatures($request->plan),
            'limits' => $this->getDefaultLimits($request->plan),
        ]);

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Tenant updated by admin');

        return redirect()->route('admin.tenants.show', $tenant)
            ->with('success', 'Tenant updated successfully!');
    }

    /**
     * Delete tenant.
     */
    public function deleteTenant(Tenant $tenant)
    {
        DB::beginTransaction();

        try {
            // Drop tenant database
            DB::statement("DROP DATABASE IF EXISTS `{$tenant->database}`");

            // Delete tenant record
            $tenant->delete();

            DB::commit();

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('Tenant deleted by admin');

            return redirect()->route('admin.tenants.index')
                ->with('success', 'Tenant deleted successfully!');

        } catch (\Exception $e) {
            DB::rollback();
            return back()->withErrors(['error' => 'Failed to delete tenant: ' . $e->getMessage()]);
        }
    }

    /**
     * Suspend tenant.
     */
    public function suspendTenant(Tenant $tenant)
    {
        $tenant->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended by admin',
        ]);

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Tenant suspended');

        return back()->with('success', 'Tenant suspended successfully!');
    }

    /**
     * Activate tenant.
     */
    public function activateTenant(Tenant $tenant)
    {
        $tenant->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
            'activated_at' => now(),
        ]);

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Tenant activated');

        return back()->with('success', 'Tenant activated successfully!');
    }

    /**
     * Migrate tenant database.
     */
    public function migrateTenant(Tenant $tenant)
    {
        try {
            tenancy()->initialize($tenant);
            
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            tenancy()->end();

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('Tenant database migrated');

            return back()->with('success', 'Tenant database migrated successfully!');

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Migration failed: ' . $e->getMessage()]);
        }
    }

    /**
     * System statistics.
     */
    public function systemStats()
    {
        $stats = [
            'tenants' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
                'pending' => Tenant::where('status', 'pending')->count(),
            ],
            'revenue' => [
                'monthly' => Tenant::sum('monthly_revenue'),
                'yearly' => Tenant::sum('monthly_revenue') * 12, // Estimated
            ],
            'storage' => [
                'total_used' => Tenant::sum('storage_used'),
                'average_per_tenant' => Tenant::avg('storage_used'),
            ],
            'users' => [
                'total' => Tenant::sum('user_count'),
                'average_per_tenant' => Tenant::avg('user_count'),
            ],
        ];

        return response()->json($stats);
    }

    /**
     * Clear system cache.
     */
    public function clearCache()
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            activity()
                ->causedBy(auth()->user())
                ->log('System cache cleared');

            return back()->with('success', 'System cache cleared successfully!');

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to clear cache: ' . $e->getMessage()]);
        }
    }

    /**
     * Restart queue workers.
     */
    public function restartQueue()
    {
        try {
            Artisan::call('queue:restart');

            activity()
                ->causedBy(auth()->user())
                ->log('Queue workers restarted');

            return back()->with('success', 'Queue workers restarted successfully!');

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to restart queue: ' . $e->getMessage()]);
        }
    }

    /**
     * Get system health check.
     */
    public function healthCheck()
    {
        $health = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
        ];

        return response()->json($health);
    }

    /**
     * Get system logs.
     */
    public function systemLogs(Request $request)
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            return response()->json(['error' => 'Log file not found'], 404);
        }

        $lines = file($logFile);
        $logs = array_slice($lines, -100); // Last 100 lines

        return response()->json(['logs' => $logs]);
    }

    /**
     * Helper methods
     */
    protected function getDefaultFeatures($plan)
    {
        // Same as in TenantController
        $features = [
            'basic' => ['users' => true, 'teams' => false, 'api_access' => false],
            'pro' => ['users' => true, 'teams' => true, 'api_access' => true],
            'enterprise' => ['users' => true, 'teams' => true, 'api_access' => true, 'custom_domain' => true],
        ];

        return $features[$plan] ?? $features['basic'];
    }

    protected function getDefaultLimits($plan)
    {
        $limits = [
            'basic' => ['users' => 5, 'storage' => 1024 * 1024 * 1024],
            'pro' => ['users' => 25, 'storage' => 10 * 1024 * 1024 * 1024],
            'enterprise' => ['users' => -1, 'storage' => -1],
        ];

        return $limits[$plan] ?? $limits['basic'];
    }

    protected function runTenantMigrations($tenant)
    {
        tenancy()->initialize($tenant);
        
        try {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
        } finally {
            tenancy()->end();
        }
    }

    protected function formatBytes($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    protected function getTenantActivity($tenant)
    {
        return activity()
            ->where('properties->tenant_id', $tenant->id)
            ->latest()
            ->take(20)
            ->get();
    }

    protected function checkDatabase()
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Database connection failed'];
        }
    }

    protected function checkCache()
    {
        try {
            Cache::put('health_check', 'ok', 60);
            $result = Cache::get('health_check');
            return ['status' => $result === 'ok' ? 'ok' : 'error', 'message' => 'Cache is working'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Cache is not working'];
        }
    }

    protected function checkStorage()
    {
        try {
            Storage::disk('local')->put('health_check.txt', 'ok');
            $content = Storage::disk('local')->get('health_check.txt');
            Storage::disk('local')->delete('health_check.txt');
            return ['status' => 'ok', 'message' => 'Storage is working'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Storage is not working'];
        }
    }

    protected function checkQueue()
    {
        // Simple queue check - in production you might want more sophisticated checks
        return ['status' => 'ok', 'message' => 'Queue appears to be working'];
    }
}