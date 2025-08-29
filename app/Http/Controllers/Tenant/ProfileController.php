<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Show user profile.
     */
    public function show()
    {
        $user = auth()->user();
        $tenant = tenant();
        
        return view('tenant.profile.show', compact('user', 'tenant'));
    }

    /**
     * Show profile edit form.
     */
    public function edit()
    {
        $user = auth()->user();
        return view('tenant.profile.edit', compact('user'));
    }

    /**
     * Update profile.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $tenant = tenant();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->where(function ($query) use ($tenant) {
                    return $query->where('tenant_id', $tenant->id);
                })->ignore($user->id)
            ],
        ]);

        $user->update($request->only(['name', 'email']));

        return back()->with('success', 'Profile updated successfully!');
    }
}
