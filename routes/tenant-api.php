<?php

use App\Http\Controllers\Api\Tenant\AuthController;
use App\Http\Controllers\Api\Tenant\UserController;
use App\Http\Controllers\Api\Tenant\TeamController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for tenant applications. These routes are loaded
| within tenant context and provide REST API functionality for tenant apps.
|
*/

// Public routes (no authentication required)
Route::prefix('v1')->group(function () {
    
    // Tenant information
    Route::get('/tenant', function (Request $request) {
        $tenant = tenant();
        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->primary_domain,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'features' => $tenant->features,
        ]);
    });
    
    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'tenant' => tenant()->id,
            'timestamp' => now()->toISOString(),
        ]);
    });
    
    // Authentication endpoints
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

// Protected routes (authentication required)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:tenant-api'])->group(function () {
    
    // Authentication
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    });
    
    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/recent-activity', [DashboardController::class, 'recentActivity']);
        Route::get('/charts/{type}', [DashboardController::class, 'chartData']);
        Route::get('/notifications', [DashboardController::class, 'notifications']);
        Route::post('/notifications/{id}/read', [DashboardController::class, 'markNotificationRead']);
    });
    
    // User Management
    Route::apiResource('users', UserController::class);
    Route::prefix('users')->group(function () {
        Route::get('/search', [UserController::class, 'search']);
        Route::post('/{user}/activate', [UserController::class, 'activate']);
        Route::post('/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/{user}/invite', [UserController::class, 'invite']);
        Route::post('/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
        Route::get('/{user}/permissions', [UserController::class, 'permissions']);
        Route::post('/{user}/permissions', [UserController::class, 'updatePermissions']);
        Route::get('/{user}/activity', [UserController::class, 'activity']);
    });
    
    // Team Management
    Route::apiResource('teams', TeamController::class);
    Route::prefix('teams')->group(function () {
        Route::get('/search', [TeamController::class, 'search']);
        Route::get('/{team}/members', [TeamController::class, 'members']);
        Route::post('/{team}/members', [TeamController::class, 'addMember']);
        Route::delete('/{team}/members/{user}', [TeamController::class, 'removeMember']);
        Route::patch('/{team}/members/{user}', [TeamController::class, 'updateMember']);
        Route::post('/{team}/invite', [TeamController::class, 'inviteMembers']);
    });
    
    // File Management
    Route::prefix('files')->group(function () {
        Route::post('/upload', [UserController::class, 'uploadFile']);
        Route::get('/', [UserController::class, 'listFiles']);
        Route::get('/{file}', [UserController::class, 'getFile']);
        Route::delete('/{file}', [UserController::class, 'deleteFile']);
        Route::post('/{file}/share', [UserController::class, 'shareFile']);
    });
    
    // Settings (Admin only)
    Route::middleware(['role:admin'])->prefix('settings')->group(function () {
        Route::get('/general', [SettingsController::class, 'getGeneral']);
        Route::patch('/general', [SettingsController::class, 'updateGeneral']);
        Route::get('/security', [SettingsController::class, 'getSecurity']);
        Route::patch('/security', [SettingsController::class, 'updateSecurity']);
        Route::get('/email', [SettingsController::class, 'getEmail']);
        Route::patch('/email', [SettingsController::class, 'updateEmail']);
        Route::post('/email/test', [SettingsController::class, 'testEmail']);
        Route::get('/integrations', [SettingsController::class, 'getIntegrations']);
        Route::patch('/integrations', [SettingsController::class, 'updateIntegrations']);
        
        // Roles & Permissions
        Route::get('/roles', [SettingsController::class, 'getRoles']);
        Route::post('/roles', [SettingsController::class, 'createRole']);
        Route::patch('/roles/{role}', [SettingsController::class, 'updateRole']);
        Route::delete('/roles/{role}', [SettingsController::class, 'deleteRole']);
        Route::get('/permissions', [SettingsController::class, 'getPermissions']);
    });
    
    // Billing & Subscription (Admin/Billing role)
    Route::middleware(['role:admin|billing'])->prefix('billing')->group(function () {
        Route::get('/subscription', [SettingsController::class, 'getSubscription']);
        Route::get('/plans', [SettingsController::class, 'getPlans']);
        Route::post('/subscribe/{plan}', [SettingsController::class, 'subscribe']);
        Route::post('/cancel-subscription', [SettingsController::class, 'cancelSubscription']);
        Route::post('/resume-subscription', [SettingsController::class, 'resumeSubscription']);
        Route::get('/invoices', [SettingsController::class, 'getInvoices']);
        Route::get('/usage', [SettingsController::class, 'getUsage']);
        Route::get('/payment-methods', [SettingsController::class, 'getPaymentMethods']);
        Route::post('/payment-methods', [SettingsController::class, 'addPaymentMethod']);
        Route::delete('/payment-methods/{method}', [SettingsController::class, 'removePaymentMethod']);
    });
    
    // Analytics & Reports
    Route::prefix('analytics')->group(function () {
        Route::get('/overview', [DashboardController::class, 'analyticsOverview']);
        Route::get('/users', [DashboardController::class, 'userAnalytics']);
        Route::get('/teams', [DashboardController::class, 'teamAnalytics']);
        Route::get('/activity', [DashboardController::class, 'activityAnalytics']);
        Route::get('/export/{type}', [DashboardController::class, 'exportData']);
    });
    
    // Webhooks (for integrations)
    Route::prefix('webhooks')->group(function () {
        Route::get('/', [SettingsController::class, 'getWebhooks']);
        Route::post('/', [SettingsController::class, 'createWebhook']);
        Route::patch('/{webhook}', [SettingsController::class, 'updateWebhook']);
        Route::delete('/{webhook}', [SettingsController::class, 'deleteWebhook']);
        Route::post('/{webhook}/test', [SettingsController::class, 'testWebhook']);
    });
    
    // Activity Logs
    Route::prefix('activity')->group(function () {
        Route::get('/', [DashboardController::class, 'getActivityLogs']);
        Route::get('/export', [DashboardController::class, 'exportActivityLogs']);
        Route::delete('/cleanup', [DashboardController::class, 'cleanupActivityLogs']);
    });
    
    // Tenant-specific custom endpoints
    Route::prefix('custom')->group(function () {
        // Add your custom business logic endpoints here
        Route::get('/modules', function () {
            return response()->json([
                'modules' => tenant()->features['modules'] ?? [],
                'enabled' => tenant()->features['custom_modules'] ?? false,
            ]);
        });
        
        Route::get('/limits', function () {
            return response()->json([
                'current_usage' => [
                    'users' => tenant()->user_count,
                    'storage' => tenant()->storage_used,
                    'api_calls' => tenant()->api_calls_count,
                ],
                'limits' => tenant()->limits ?? [],
            ]);
        });
    });
});

// Webhook endpoints (no authentication, but with verification)
Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', [SettingsController::class, 'stripeWebhook']);
    Route::post('/paypal', [SettingsController::class, 'paypalWebhook']);
    Route::post('/mailgun', [SettingsController::class, 'mailgunWebhook']);
});

// Public API endpoints (rate limited but no auth)
Route::prefix('public')->middleware(['throttle:30,1'])->group(function () {
    Route::get('/status', function () {
        $tenant = tenant();
        return response()->json([
            'tenant' => $tenant->name,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'maintenance' => $tenant->settings['maintenance_mode'] ?? false,
        ]);
    });
});

// Error handling for API routes
Route::fallback(function () {
    return response()->json([
        'message' => 'API endpoint not found.',
        'tenant' => tenant()->id,
    ], 404);
});