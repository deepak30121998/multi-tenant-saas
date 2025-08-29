<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return view('tenant.dashboard', [
            'tenant' => tenant(),
            'users' => \App\Models\User::all()
        ]);
    });
    
    // Add your tenant-specific routes here
    Route::resource('users', UserController::class);
    Route::resource('posts', PostController::class);
});