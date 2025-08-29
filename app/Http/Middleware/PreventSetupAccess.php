<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class PreventSetupAccess
{
    public function handle(Request $request, Closure $next)
    {
        // If super admin exists, block setup routes
        if (User::where('role', 'super_admin')->exists()) {
            abort(404);
        }

        return $next($request);
    }
}