<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// routes/web.php
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', function () {
            return view('welcome');
        });
        
        Route::get('/register', [TenantController::class, 'showRegistration']);
        Route::post('/register', [TenantController::class, 'register']);
        
        // Add your central app routes here
    });
}
