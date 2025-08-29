<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function showRegistration()
    {
        return view('tenant.register');
    }
    
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:tenants,email',
            'domain' => 'required|string|unique:domains,domain',
            'plan' => 'required|in:basic,premium,enterprise',
        ]);
        
        // Create tenant
        $tenant = Tenant::create([
            'id' => Str::random(8),
            'name' => $request->name,
            'email' => $request->email,
            'plan' => $request->plan,
            'status' => 'active',
        ]);
        
        // Create domain
        $tenant->domains()->create([
            'domain' => $request->domain . '.yourapp.com',
        ]);
        
        return redirect()->away('https://' . $request->domain . '.yourapp.com')
            ->with('success', 'Tenant created successfully!');
    }
}