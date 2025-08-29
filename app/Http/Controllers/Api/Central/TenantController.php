<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    /**
     * Get all tenants.
     */
    public function index(Request $request)
    {
        $query = Tenant::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('admin_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tenants = $query->withCount('users')
            ->paginate($request->get('per_page', 15));

        return response()->json($tenants);
    }

    /**
     * Check tenant availability.
     */
    public function checkAvailability(Request $request)
    {
        $request->validate(['slug' => 'required|string|max:100']);

        $slug = Str::slug($request->slug);
        $domain = $slug . '.localhost';

        $available = !Tenant::where('slug', $slug)
                           ->orWhere('primary_domain', $domain)
                           ->exists();

        return response()->json([
            'available' => $available,
            'slug' => $slug,
            'domain' => $domain,
        ]);
    }

    /**
     * Get tenant stats.
     */
    public function stats(Tenant $tenant)
    {
        return response()->json([
            'users_count' => $tenant->users()->count(),
            'storage_used' => $tenant->storage_used,
            'last_activity' => $tenant->users()->max('last_login_at'),
            'created_at' => $tenant->created_at,
            'status' => $tenant->status,
        ]);
    }

    /**
     * Suspend tenant.
     */
    public function suspend(Tenant $tenant)
    {
        $tenant->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended by admin via API',
        ]);

        return response()->json(['message' => 'Tenant suspended successfully']);
    }

    /**
     * Activate tenant.
     */
    public function activate(Tenant $tenant)
    {
        $tenant->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        return response()->json(['message' => 'Tenant activated successfully']);
    }
}