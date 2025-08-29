<?php

use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\ProfileController;
use App\Http\Controllers\Tenant\SettingsController;
use App\Http\Controllers\Tenant\BillingController;
use App\Http\Controllers\Tenant\TeamController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group and tenant context.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Landing & Public Routes
    |--------------------------------------------------------------------------
    */
    
    Route::get('/', function () {
        $tenant = tenant();
        return view('tenant.welcome', compact('tenant'));
    })->name('tenant.home');
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Authentication Routes
    |--------------------------------------------------------------------------
    */
    
    Route::middleware('guest')->group(function () {
        // Registration
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register']);
        
        // Login
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login']);
        
        // Password Reset
        Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
        Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
        Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
    });
    
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        
        // Email Verification
        Route::get('/email/verify', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])->name('verification.send');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Application Routes
    |--------------------------------------------------------------------------
    */
    
    Route::prefix('app')->middleware(['auth', 'verified'])->group(function () {
        
        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');
        
        /*
        |--------------------------------------------------------------------------
        | User Management Routes
        |--------------------------------------------------------------------------
        */
        
        Route::middleware(['role:admin|manager'])->group(function () {
            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/', [UserController::class, 'index'])->name('index');
                Route::get('/create', [UserController::class, 'create'])->name('create');
                Route::post('/', [UserController::class, 'store'])->name('store');
                Route::get('/{user}', [UserController::class, 'show'])->name('show');
                Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
                Route::patch('/{user}', [UserController::class, 'update'])->name('update');
                Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
                
                // User Actions
                Route::post('/{user}/activate', [UserController::class, 'activate'])->name('activate');
                Route::post('/{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
                Route::post('/{user}/resend-invitation', [UserController::class, 'resendInvitation'])->name('resend-invitation');
            });
        });
        
        /*
        |--------------------------------------------------------------------------
        | Team Management Routes
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('teams')->name('teams.')->group(function () {
            Route::get('/', [TeamController::class, 'index'])->name('index');
            Route::get('/create', [TeamController::class, 'create'])->name('create')->middleware('can:create,App\Models\Team');
            Route::post('/', [TeamController::class, 'store'])->name('store')->middleware('can:create,App\Models\Team');
            Route::get('/{team}', [TeamController::class, 'show'])->name('show');
            Route::get('/{team}/edit', [TeamController::class, 'edit'])->name('edit');
            Route::patch('/{team}', [TeamController::class, 'update'])->name('update');
            Route::delete('/{team}', [TeamController::class, 'destroy'])->name('destroy');
            
            // Team Members
            Route::prefix('{team}/members')->name('members.')->group(function () {
                Route::get('/', [TeamController::class, 'members'])->name('index');
                Route::post('/invite', [TeamController::class, 'inviteMember'])->name('invite');
                Route::delete('/{user}', [TeamController::class, 'removeMember'])->name('remove');
                Route::patch('/{user}/role', [TeamController::class, 'updateMemberRole'])->name('update-role');
            });
        });
        
        /*
        |--------------------------------------------------------------------------
        | Profile Management Routes
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            Route::get('/edit', [ProfileController::class, 'edit'])->name('edit');
            Route::patch('/', [ProfileController::class, 'update'])->name('update');
            Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
            
            // Avatar
            Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar.update');
            Route::delete('/avatar', [ProfileController::class, 'removeAvatar'])->name('avatar.remove');
            
            // Password
            Route::get('/password', [ProfileController::class, 'showPasswordForm'])->name('password');
            Route::patch('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
            
            // Two Factor Authentication
            Route::get('/two-factor', [ProfileController::class, 'showTwoFactor'])->name('two-factor');
            Route::post('/two-factor', [ProfileController::class, 'enableTwoFactor'])->name('two-factor.enable');
            Route::delete('/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('two-factor.disable');
            Route::get('/two-factor/recovery-codes', [ProfileController::class, 'showRecoveryCodes'])->name('two-factor.recovery-codes');
            Route::post('/two-factor/recovery-codes', [ProfileController::class, 'generateRecoveryCodes'])->name('two-factor.recovery-codes.generate');
        });
        
        /*
        |--------------------------------------------------------------------------
        | Tenant Settings Routes (Admin Only)
        |--------------------------------------------------------------------------
        */
        
        Route::middleware(['role:admin'])->group(function () {
            Route::prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [SettingsController::class, 'index'])->name('index');
                
                // General Settings
                Route::get('/general', [SettingsController::class, 'general'])->name('general');
                Route::patch('/general', [SettingsController::class, 'updateGeneral'])->name('general.update');
                
                // Security Settings
                Route::get('/security', [SettingsController::class, 'security'])->name('security');
                Route::patch('/security', [SettingsController::class, 'updateSecurity'])->name('security.update');
                
                // Email Settings
                Route::get('/email', [SettingsController::class, 'email'])->name('email');
                Route::patch('/email', [SettingsController::class, 'updateEmail'])->name('email.update');
                Route::post('/email/test', [SettingsController::class, 'testEmail'])->name('email.test');
                
                // Integrations
                Route::get('/integrations', [SettingsController::class, 'integrations'])->name('integrations');
                Route::patch('/integrations', [SettingsController::class, 'updateIntegrations'])->name('integrations.update');
                
                // Roles & Permissions
                Route::prefix('permissions')->name('permissions.')->group(function () {
                    Route::get('/', [SettingsController::class, 'permissions'])->name('index');
                    Route::get('/roles', [SettingsController::class, 'roles'])->name('roles');
                    Route::post('/roles', [SettingsController::class, 'storeRole'])->name('roles.store');
                    Route::patch('/roles/{role}', [SettingsController::class, 'updateRole'])->name('roles.update');
                    Route::delete('/roles/{role}', [SettingsController::class, 'deleteRole'])->name('roles.destroy');
                });
            });
        });
        
        /*
        |--------------------------------------------------------------------------
        | Billing & Subscription Routes
        |--------------------------------------------------------------------------
        */
        
        Route::middleware(['role:admin|billing'])->group(function () {
            Route::prefix('billing')->name('billing.')->group(function () {
                Route::get('/', [BillingController::class, 'index'])->name('index');
                
                // Subscription Management
                Route::get('/subscription', [BillingController::class, 'subscription'])->name('subscription');
                Route::post('/subscribe/{plan}', [BillingController::class, 'subscribe'])->name('subscribe');
                Route::post('/cancel-subscription', [BillingController::class, 'cancelSubscription'])->name('cancel');
                Route::post('/resume-subscription', [BillingController::class, 'resumeSubscription'])->name('resume');
                Route::post('/change-plan/{plan}', [BillingController::class, 'changePlan'])->name('change-plan');
                
                // Payment Methods
                Route::get('/payment-methods', [BillingController::class, 'paymentMethods'])->name('payment-methods');
                Route::post('/payment-methods', [BillingController::class, 'addPaymentMethod'])->name('payment-methods.add');
                Route::delete('/payment-methods/{method}', [BillingController::class, 'removePaymentMethod'])->name('payment-methods.remove');
                Route::post('/payment-methods/{method}/default', [BillingController::class, 'setDefaultPaymentMethod'])->name('payment-methods.default');
                
                // Invoices
                Route::get('/invoices', [BillingController::class, 'invoices'])->name('invoices');
                Route::get('/invoices/{invoice}', [BillingController::class, 'downloadInvoice'])->name('invoices.download');
                
                // Usage & Analytics
                Route::get('/usage', [BillingController::class, 'usage'])->name('usage');
            });
        });
        
        /*
        |--------------------------------------------------------------------------
        | API Routes for Tenant App
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('api')->name('api.')->group(function () {
            Route::middleware(['throttle:120,1'])->group(function () {
                
                // Dashboard Data
                Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
                Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])->name('dashboard.chart');
                
                // User Search
                Route::get('/users/search', [UserController::class, 'search'])->name('users.search');
                
                // Team Management
                Route::get('/teams/search', [TeamController::class, 'search'])->name('teams.search');
                
                // File Upload
                Route::post('/files/upload', [UserController::class, 'uploadFile'])->name('files.upload');
                Route::delete('/files/{file}', [UserController::class, 'deleteFile'])->name('files.delete');
                
                // Notifications
                Route::get('/notifications', [UserController::class, 'notifications'])->name('notifications.index');
                Route::post('/notifications/{notification}/read', [UserController::class, 'markNotificationRead'])->name('notifications.read');
                Route::post('/notifications/read-all', [UserController::class, 'markAllNotificationsRead'])->name('notifications.read-all');
            });
        });
    });
    
    /*
    |--------------------------------------------------------------------------
    | Public API Routes (without authentication)
    |--------------------------------------------------------------------------
    */
    
    Route::prefix('api/public')->name('api.public.')->group(function () {
        Route::middleware(['throttle:30,1'])->group(function () {
            Route::get('/tenant/info', function () {
                $tenant = tenant();
                return response()->json([
                    'name' => $tenant->name,
                    'domain' => $tenant->primary_domain,
                    'status' => $tenant->status,
                ]);
            })->name('tenant.info');
        });
    });
});