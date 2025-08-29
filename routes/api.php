<?php

use App\Http\Controllers\Api\Central\TenantController;
use App\Http\Controllers\Api\Central\AdminController;
use App\Http\Controllers\Api\Central\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the central application. These routes
| handle super admin functions, tenant management, and system operations.
|
*/

// API Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'central',
        'timestamp' => now()->toISOString(),
    ]);
});

// Public API Routes
Route::prefix('v1')->group(function () {
    
    // Tenant availability check (for registration)
    Route::get('/tenants/check-availability', [TenantController::class, 'checkAvailability']);
    
    // Public tenant information
    Route::get('/tenants/{slug}/info', [TenantController::class, 'getTenantInfo']);
    
    // Plans and pricing
    Route::get('/plans', [TenantController::class, 'getPlans']);
    
    // System status
    Route::get('/status', function () {
        return response()->json([
            'status' => 'operational',
            'version' => config('app.version', '1.0.0'),
            'maintenance' => config('app.maintenance', false),
        ]);
    });
});

// Protected API Routes (Super Admin)
Route::prefix('v1')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    
    // Admin Authentication
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
    });
    
    // Tenant Management
    Route::apiResource('tenants', TenantController::class);
    Route::prefix('tenants')->group(function () {
        Route::get('/search', [TenantController::class, 'search']);
        Route::post('/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::post('/{tenant}/activate', [TenantController::class, 'activate']);
        Route::post('/{tenant}/migrate', [TenantController::class, 'migrate']);
        Route::get('/{tenant}/stats', [TenantController::class, 'stats']);
        Route::get('/{tenant}/users', [TenantController::class, 'getTenantUsers']);
        Route::post('/{tenant}/impersonate', [TenantController::class, 'impersonate']);
    });
    
    // System Management
    Route::prefix('system')->group(function () {
        Route::get('/stats', [AdminController::class, 'systemStats']);
        Route::get('/logs', [AdminController::class, 'getLogs']);
        Route::post('/cache/clear', [AdminController::class, 'clearCache']);
        Route::post('/queue/restart', [AdminController::class, 'restartQueue']);
        Route::get('/health-check', [AdminController::class, 'healthCheck']);
        Route::get('/disk-usage', [AdminController::class, 'diskUsage']);
    });
    
    // User Management (Central Users)
    Route::apiResource('users', AdminController::class);
    Route::prefix('users')->group(function () {
        Route::get('/search', [AdminController::class, 'searchUsers']);
        Route::post('/{user}/activate', [AdminController::class, 'activateUser']);
        Route::post('/{user}/deactivate', [AdminController::class, 'deactivateUser']);
    });
    
    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/overview', [AdminController::class, 'analyticsOverview']);
        Route::get('/tenants', [AdminController::class, 'tenantAnalytics']);
        Route::get('/revenue', [AdminController::class, 'revenueAnalytics']);
        Route::get('/usage', [AdminController::class, 'usageAnalytics']);
        Route::get('/growth', [AdminController::class, 'growthAnalytics']);
    });
    
    // Billing Management
    Route::prefix('billing')->group(function () {
        Route::get('/overview', [AdminController::class, 'billingOverview']);
        Route::get('/plans', [AdminController::class, 'getPlans']);
        Route::post('/plans', [AdminController::class, 'createPlan']);
        Route::patch('/plans/{plan}', [AdminController::class, 'updatePlan']);
        Route::delete('/plans/{plan}', [AdminController::class, 'deletePlan']);
    });
});

// Webhook Routes (No authentication but with verification)
Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', [TenantController::class, 'stripeWebhook']);
    Route::post('/paypal', [TenantController::class, 'paypalWebhook']);
    Route::post('/mailgun', [TenantController::class, 'mailgunWebhook']);
});

// Fallback for API routes
Route::fallback(function () {
    return response()->json([
        'message' => 'API endpoint not found.',
        'app' => 'central'
    ], 404);
});