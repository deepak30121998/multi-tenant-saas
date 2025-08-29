<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Show the super admin login form.
     */
    public function showLoginForm()
    {
        return view('admin.auth.login');
    }

    /**
     * Handle super admin login.
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);

        // Check rate limiting
        $key = Str::transliterate(Str::lower($request->ip()));

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again in ' . $seconds . ' seconds.'],
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Check if user exists and is super admin
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !$user->is_super_admin) {
            RateLimiter::hit($key, 300); // 5 minute lockout
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records or insufficient permissions.'],
            ]);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended. Please contact support.'],
            ]);
        }

        // Login successful - clear rate limiter
        RateLimiter::clear($key);

        // Use web guard for session-based authentication
        Auth::guard('web')->login($user, $remember);

        // Update last login
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Log the login
        activity()
            ->causedBy($user)
            ->log('Super admin logged in');

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'))
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * Handle super admin logout.
     */
    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            activity()
                ->causedBy($user)
                ->log('Super admin logged out');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'You have been logged out successfully.');
    }

    /**
     * Show the forgot password form.
     */
    public function showForgotPasswordForm()
    {
        return view('admin.auth.forgot-password');
    }

    /**
     * Send password reset email.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)
                   ->where('is_super_admin', true)
                   ->where('status', 'active')
                   ->first();

        if (!$user) {
            // Don't reveal if email exists or not
            return back()->with('success', 'If your email exists in our records, you will receive a password reset link.');
        }

        // Generate reset token
        $token = Str::random(60);
        
        // Store token in database (you might want to create a password_resets table)
        cache()->put(
            'password_reset_' . $user->id,
            ['token' => $token, 'email' => $user->email],
            now()->addHour()
        );

        // Send email (implement your email sending logic)
        // Mail::to($user)->send(new ResetPasswordMail($token));

        activity()
            ->causedBy($user)
            ->log('Password reset requested');

        return back()->with('success', 'Password reset link has been sent to your email.');
    }

    /**
     * Show reset password form.
     */
    public function showResetPasswordForm(Request $request, $token)
    {
        return view('admin.auth.reset-password', ['token' => $token]);
    }

    /**
     * Reset password.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        // Find user with reset token
        $users = User::where('is_super_admin', true)->get();
        $user = null;
        
        foreach ($users as $u) {
            $resetData = cache()->get('password_reset_' . $u->id);
            if ($resetData && 
                $resetData['token'] === $request->token && 
                $resetData['email'] === $request->email) {
                $user = $u;
                break;
            }
        }

        if (!$user) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired reset token.'],
            ]);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->password),
            'updated_at' => now(),
        ]);

        // Clear reset token
        cache()->forget('password_reset_' . $user->id);

        activity()
            ->causedBy($user)
            ->log('Password reset completed');

        return redirect()->route('admin.login')
            ->with('success', 'Your password has been reset. Please login with your new password.');
    }

    /**
     * Update admin profile.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'current_password' => 'nullable|required_with:password',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Check current password if new password is provided
        if ($request->filled('password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return back()->withErrors([
                    'current_password' => 'The current password is incorrect.'
                ])->withInput();
            }
        }

        // Update user
        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
        ];

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $user->update($updateData);

        activity()
            ->causedBy($user)
            ->log('Admin profile updated');

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Enable/disable two-factor authentication.
     */
    public function toggleTwoFactor(Request $request)
    {
        $user = Auth::user();
        
        $request->validate([
            'password' => 'required',
            'enable' => 'required|boolean',
        ]);

        if (!Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => 'The password is incorrect.'
            ]);
        }

        if ($request->enable) {
            // Enable 2FA logic here
            $user->update(['two_factor_enabled' => true]);
            $message = 'Two-factor authentication enabled successfully.';
            $logMessage = 'Two-factor authentication enabled';
        } else {
            // Disable 2FA logic here
            $user->update(['two_factor_enabled' => false]);
            $message = 'Two-factor authentication disabled successfully.';
            $logMessage = 'Two-factor authentication disabled';
        }

        activity()
            ->causedBy($user)
            ->log($logMessage);

        return back()->with('success', $message);
    }

    /**
     * Validate the user login request.
     */
    protected function validateLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);
    }

    /**
     * Get authenticated user information (for API).
     */
    public function me(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'roles' => $user->getRoleNames(),
            ]
        ]);
    }

    /**
     * API logout.
     */
    public function apiLogout(Request $request)
    {
        $user = $request->user();
        
        // Revoke current access token
        $request->user()->currentAccessToken()->delete();

        activity()
            ->causedBy($user)
            ->log('API logout');

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
}