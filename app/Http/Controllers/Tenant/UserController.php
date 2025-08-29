<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('role:admin|manager')->except(['show', 'search']);
    }

    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->role($request->role);
        }

        $users = $query->with(['roles'])
            ->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'))
            ->paginate(20);

        $roles = Role::all();
        $tenant = tenant();

        return view('tenant.users.index', compact('users', 'roles', 'tenant'));
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        $this->authorize('create', User::class);
        
        $roles = Role::all();
        $tenant = tenant();

        // Check user limit
        $userLimit = $tenant->limits['users'] ?? -1;
        if ($userLimit > 0 && $tenant->user_count >= $userLimit) {
            return back()->withErrors([
                'error' => 'User limit reached. Please upgrade your plan to add more users.'
            ]);
        }

        return view('tenant.users.create', compact('roles', 'tenant'));
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        
        $tenant = tenant();

        // Check user limit
        $userLimit = $tenant->limits['users'] ?? -1;
        if ($userLimit > 0 && $tenant->user_count >= $userLimit) {
            return back()->withErrors([
                'error' => 'User limit reached. Please upgrade your plan to add more users.'
            ])->withInput();
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->where(function ($query) use ($tenant) {
                    return $query->where('tenant_id', $tenant->id);
                })
            ],
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
            'send_invitation' => 'boolean',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        // Assign role
        $user->assignRole($request->role);

        // Update tenant user count
        $tenant->increment('user_count');

        // Send invitation email if requested
        if ($request->send_invitation) {
            $this->sendInvitationEmail($user, $request->password);
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('User created');

        return redirect()->route('users.index')
            ->with('success', 'User created successfully!');
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);
        
        $user->load(['roles', 'permissions']);
        
        $recentActivity = activity()
            ->causedBy($user)
            ->latest()
            ->take(20)
            ->get();

        return view('tenant.users.show', compact('user', 'recentActivity'));
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);
        
        $roles = Role::all();
        $userRoles = $user->roles->pluck('name')->toArray();

        return view('tenant.users.edit', compact('user', 'roles', 'userRoles'));
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        
        $tenant = tenant();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->where(function ($query) use ($tenant) {
                    return $query->where('tenant_id', $tenant->id);
                })->ignore($user->id)
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
            'status' => $request->status,
        ];

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $user->update($updateData);

        // Update role
        $user->syncRoles([$request->role]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('User updated');

        return redirect()->route('users.show', $user)
            ->with('success', 'User updated successfully!');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        // Prevent deleting the last admin
        if ($user->hasRole('admin') && User::role('admin')->count() <= 1) {
            return back()->withErrors([
                'error' => 'Cannot delete the last admin user.'
            ]);
        }

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return back()->withErrors([
                'error' => 'You cannot delete your own account.'
            ]);
        }

        $tenant = tenant();
        
        $user->delete();
        $tenant->decrement('user_count');

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['deleted_user' => $user->email])
            ->log('User deleted');

        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully!');
    }

    /**
     * Activate user.
     */
    public function activate(User $user)
    {
        $this->authorize('update', $user);

        $user->update(['status' => 'active']);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('User activated');

        return back()->with('success', 'User activated successfully!');
    }

    /**
     * Deactivate user.
     */
    public function deactivate(User $user)
    {
        $this->authorize('update', $user);

        // Prevent deactivating self
        if ($user->id === auth()->id()) {
            return back()->withErrors([
                'error' => 'You cannot deactivate your own account.'
            ]);
        }

        $user->update(['status' => 'inactive']);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('User deactivated');

        return back()->with('success', 'User deactivated successfully!');
    }

    /**
     * Resend invitation email.
     */
    public function resendInvitation(User $user)
    {
        $this->authorize('update', $user);

        // Generate temporary password
        $tempPassword = Str::random(12);
        $user->update(['password' => Hash::make($tempPassword)]);

        $this->sendInvitationEmail($user, $tempPassword);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('Invitation resent');

        return back()->with('success', 'Invitation resent successfully!');
    }

    /**
     * Search users (API endpoint).
     */
    public function search(Request $request)
    {
        $query = User::query();

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->with('roles')
            ->take($request->get('limit', 10))
            ->get();

        return response()->json([
            'users' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'roles' => $user->roles->pluck('name'),
                    'created_at' => $user->created_at,
                    'last_login_at' => $user->last_login_at,
                ];
            })
        ]);
    }

    /**
     * Upload user file.
     */
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $tenant = tenant();
        $user = auth()->user();

        // Check storage limit
        $storageLimit = $tenant->limits['storage'] ?? 0;
        if ($storageLimit > 0) {
            $fileSize = $request->file('file')->getSize();
            if (($tenant->storage_used + $fileSize) > $storageLimit) {
                return response()->json([
                    'error' => 'Storage limit exceeded. Please upgrade your plan.'
                ], 422);
            }
        }

        $file = $request->file('file');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = "tenant/{$tenant->id}/files/" . $filename;

        // Store file
        Storage::putFileAs('tenant/' . $tenant->id . '/files', $file, $filename);

        // Update tenant storage usage
        $tenant->increment('storage_used', $file->getSize());

        // Log the upload
        activity()
            ->causedBy($user)
            ->withProperties([
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'path' => $path,
            ])
            ->log('File uploaded');

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'url' => Storage::url($path),
        ]);
    }

    /**
     * Delete user file.
     */
    public function deleteFile(Request $request, $filename)
    {
        $tenant = tenant();
        $path = "tenant/{$tenant->id}/files/{$filename}";

        if (!Storage::exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $fileSize = Storage::size($path);
        Storage::delete($path);

        // Update tenant storage usage
        $tenant->decrement('storage_used', $fileSize);

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['filename' => $filename])
            ->log('File deleted');

        return response()->json(['success' => true]);
    }

    /**
     * Get user activity.
     */
    public function activity(User $user)
    {
        $this->authorize('view', $user);

        $activity = activity()
            ->causedBy($user)
            ->latest()
            ->paginate(20);

        return response()->json([
            'activity' => $activity->items(),
            'pagination' => [
                'current_page' => $activity->currentPage(),
                'last_page' => $activity->lastPage(),
                'per_page' => $activity->perPage(),
                'total' => $activity->total(),
            ]
        ]);
    }

    /**
     * Send invitation email to user.
     */
    protected function sendInvitationEmail(User $user, $password)
    {
        $tenant = tenant();
        
        // In a real application, you would send an actual email
        // Mail::to($user->email)->send(new UserInvitationMail($user, $tenant, $password));
        
        // For now, we'll just log it
        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties(['invitation_sent' => true])
            ->log('Invitation email sent');
    }
}