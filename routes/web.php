<?php

use App\Http\Controllers\Setup\SetupController;
use App\Http\Controllers\Central\TenantController;
use App\Http\Controllers\Central\AuthController;
use App\Http\Controllers\Central\AdminController;
use App\Http\Controllers\Central\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Application Routes
|--------------------------------------------------------------------------
|
| These routes are loaded for the central application and are used
| for managing tenants, super admin functions, and tenant registration.
|
*/

// Default welcome page
Route::get('/', function () {
    return view('welcome');
})->name('home');

// Setup routes (for initial application setup)
Route::middleware(['web', 'throttle:5,1'])->group(function () {
    Route::get('/setup', [SetupController::class, 'showSetup'])->name('setup.show');
    Route::post('/setup/super-admin', [SetupController::class, 'createSuperAdmin'])->name('setup.super-admin');
});

/*
|--------------------------------------------------------------------------
| Central Domain Routes
|--------------------------------------------------------------------------
|
| Routes that run on central domains for tenant management
|
*/

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        
        // Landing and marketing pages
        Route::get('/', function () {
            return view('central.landing');
        })->name('central.home');
        
        Route::get('/pricing', function () {
            return view('central.pricing');
        })->name('central.pricing');
        
        Route::get('/features', function () {
            return view('central.features');
        })->name('central.features');
        
        // Tenant Registration Routes
        Route::middleware(['web', 'throttle:10,1'])->group(function () {
            Route::get('/register', [TenantController::class, 'showRegistration'])->name('tenant.register.form');
            Route::post('/register', [TenantController::class, 'register'])->name('tenant.register');
            Route::get('/register/success', [TenantController::class, 'registrationSuccess'])->name('tenant.register.success');
            Route::get('/verify-tenant/{token}', [TenantController::class, 'verifyTenant'])->name('tenant.verify');
        });
        
        // Super Admin Authentication Routes
        Route::prefix('admin')->name('admin.')->group(function () {
            
            // Authentication routes
            Route::middleware('guest:admin')->group(function () {
                Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
                Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
            });
            
            // Protected admin routes
            Route::middleware(['auth:admin', 'role:super_admin'])->group(function () {
                Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
                
                // Admin Dashboard
                Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
                
                // Tenant Management
                Route::prefix('tenants')->name('tenants.')->group(function () {
                    Route::get('/', [AdminController::class, 'tenants'])->name('index');
                    Route::get('/create', [AdminController::class, 'createTenant'])->name('create');
                    Route::post('/', [AdminController::class, 'storeTenant'])->name('store');
                    Route::get('/{tenant}', [AdminController::class, 'showTenant'])->name('show');
                    Route::get('/{tenant}/edit', [AdminController::class, 'editTenant'])->name('edit');
                    Route::patch('/{tenant}', [AdminController::class, 'updateTenant'])->name('update');
                    Route::delete('/{tenant}', [AdminController::class, 'deleteTenant'])->name('destroy');
                    
                    // Tenant Actions
                    Route::post('/{tenant}/suspend', [AdminController::class, 'suspendTenant'])->name('suspend');
                    Route::post('/{tenant}/activate', [AdminController::class, 'activateTenant'])->name('activate');
                    Route::post('/{tenant}/impersonate', [AdminController::class, 'impersonateTenant'])->name('impersonate');
                    
                    // Tenant Database Management
                    Route::post('/{tenant}/migrate', [AdminController::class, 'migrateTenant'])->name('migrate');
                    Route::post('/{tenant}/seed', [AdminController::class, 'seedTenant'])->name('seed');
                });
                
                // User Management (Central Users)
                Route::prefix('users')->name('users.')->group(function () {
                    Route::get('/', [AdminController::class, 'users'])->name('index');
                    Route::get('/create', [AdminController::class, 'createUser'])->name('create');
                    Route::post('/', [AdminController::class, 'storeUser'])->name('store');
                    Route::get('/{user}', [AdminController::class, 'showUser'])->name('show');
                    Route::get('/{user}/edit', [AdminController::class, 'editUser'])->name('edit');
                    Route::patch('/{user}', [AdminController::class, 'updateUser'])->name('update');
                    Route::delete('/{user}', [AdminController::class, 'deleteUser'])->name('destroy');
                    
                    Route::post('/{user}/suspend', [AdminController::class, 'suspendUser'])->name('suspend');
                    Route::post('/{user}/activate', [AdminController::class, 'activateUser'])->name('activate');
                });
                
                // System Management
                Route::prefix('system')->name('system.')->group(function () {
                    Route::get('/settings', [AdminController::class, 'systemSettings'])->name('settings');
                    Route::patch('/settings', [AdminController::class, 'updateSystemSettings'])->name('settings.update');
                    Route::get('/logs', [AdminController::class, 'systemLogs'])->name('logs');
                    Route::get('/maintenance', [AdminController::class, 'maintenance'])->name('maintenance');
                    Route::post('/cache/clear', [AdminController::class, 'clearCache'])->name('cache.clear');
                    Route::post('/queue/restart', [AdminController::class, 'restartQueue'])->name('queue.restart');
                    Route::get('/phpinfo', [AdminController::class, 'phpInfo'])->name('phpinfo');
                });
                
                // Analytics & Reports
                Route::prefix('analytics')->name('analytics.')->group(function () {
                    Route::get('/dashboard', [AdminController::class, 'analyticsAdmin'])->name('dashboard');
                    Route::get('/tenants', [AdminController::class, 'tenantAnalytics'])->name('tenants');
                    Route::get('/revenue', [AdminController::class, 'revenueAnalytics'])->name('revenue');
                    Route::get('/usage', [AdminController::class, 'usageAnalytics'])->name('usage');
                    Route::get('/export/{type}', [AdminController::class, 'exportAnalytics'])->name('export');
                });
                
                // Billing Management
                Route::prefix('billing')->name('billing.')->group(function () {
                    Route::get('/', [AdminController::class, 'billing'])->name('index');
                    Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
                    Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
                    Route::patch('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
                    Route::delete('/plans/{plan}', [AdminController::class, 'deletePlan'])->name('plans.destroy');
                });
                
                // Permission Management
                Route::prefix('permissions')->name('permissions.')->group(function () {
                    Route::get('/', [AdminController::class, 'permissions'])->name('index');
                    Route::get('/roles', [AdminController::class, 'roles'])->name('roles');
                    Route::post('/roles', [AdminController::class, 'storeRole'])->name('roles.store');
                    Route::patch('/roles/{role}', [AdminController::class, 'updateRole'])->name('roles.update');
                    Route::delete('/roles/{role}', [AdminController::class, 'deleteRole'])->name('roles.destroy');
                });
            });
        });
        
        // API Routes for Central App
        Route::prefix('api')->name('api.')->group(function () {
            Route::middleware(['throttle:60,1'])->group(function () {
                Route::get('/tenants/check-availability', [TenantController::class, 'checkAvailability'])->name('tenants.check');
                Route::post('/webhooks/stripe', [TenantController::class, 'stripeWebhook'])->name('webhooks.stripe');
            });
        });
    });
}

/*
|--------------------------------------------------------------------------
| Tenant Routes (Fallback for tenant domains)
|--------------------------------------------------------------------------
|
| These routes handle tenant-specific domains that don't match central domains
| They should redirect to tenant application or show appropriate messages
|
*/

// Catch-all for tenant domains
Route::fallback(function () {
    // Check if this is a valid tenant domain
    $domain = request()->getHost();
    
    // If it's a tenant domain, redirect to tenant app
    if ($tenant = \App\Models\Tenant::where('primary_domain', $domain)
                    ->orWhereJsonContains('domains', $domain)
                    ->first()) {
        
        if ($tenant->status === 'active') {
            // Redirect to tenant application
            return redirect()->away("http://{$domain}/app");
        } else {
            return view('errors.tenant-suspended', compact('tenant'));
        }
    }
    
    // If not a tenant domain, show 404
    abort(404);
});

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
|
| Routes for monitoring and health checking
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'app' => config('app.name'),
        'version' => config('app.version', '1.0.0'),
    ]);
})->name('health');

Route::get('/status', [DashboardController::class, 'systemStatus'])->name('status');