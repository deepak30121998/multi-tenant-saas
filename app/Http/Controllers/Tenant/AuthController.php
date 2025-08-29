<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    /**
     * Show the registration form.
     */
    public function showRegister()
    {
        $tenant = tenant();
        
        // Check if registration is enabled for this tenant
        if (!$tenant->settings['allow_registration'] ?? true) {
            abort(403, 'Registration is disabled for this tenant.');
        }

        return view('tenant.auth.register', compact('tenant'));
    }

    /**
     * Handle user registration.
     */
    public function register(Request $request)
    {
        $tenant = tenant();
        
        // Check if registration is enabled
        if (!$tenant->settings['allow_registration'] ?? true) {
            abort(403, 'Registration is disabled.');
        }

        // Check user limits
        $userLimit = $tenant->limits['users'] ?? -1; // -1 = unlimited
        if ($userLimit > 0 && $tenant->user_count >= $userLimit) {
            return back()->withErrors([
                'email' => 'User limit reached for this tenant. Please contact your administrator.'
            ])->withInput();
        }

        $this->validateRegistration($request);

        // Check rate limiting
        $key = 'register_' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ['Too many registration attempts. Please try again in ' . $seconds . ' seconds.'],
            ]);
        }

        try {
            // Create user
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
            ]);

            // Assign default role
            $defaultRole = Role::where('name', 'user')->first();
            if ($defaultRole) {
                $user->assignRole($defaultRole);
            }

            // Update tenant user count
            $tenant->increment('user_count');

            // Send email verification if enabled
            if ($tenant->settings['require_email_verification'] ?? true) {
                event(new Registered($user));
            } else {
                $user->markEmailAsVerified();
            }

            RateLimiter::clear($key);

            // Log the registration
            activity()
                ->causedBy($user)
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('User registered');

            // Auto login if email verification is not required
            if (!($tenant->settings['require_email_verification'] ?? true)) {
                Auth::login($user);
                return redirect()->route('tenant.dashboard')
                    ->with('success', 'Registration successful! Welcome to ' . $tenant->name);
            }

            return redirect()->route('login')
                ->with('success', 'Registration successful! Please check your email to verify your account.');

        } catch (\Exception $e) {
            RateLimiter::hit($key, 300);
            
            return back()->withErrors([
                'email' => 'Registration failed. Please try again.'
            ])->withInput();
        }
    }

    /**
     * Show the login form.
     */
    public function showLogin()
    {
        $tenant = tenant();
        return view('tenant.auth.login', compact('tenant'));
    }

    /**
     * Handle user login.
     */
    public function login(Request $request)
    {
        $tenant = tenant();
        $this->validateLogin($request);

        // Check rate limiting
        $key = 'login_' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again in ' . $seconds . ' seconds.'],
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Add tenant_id to credentials
        $credentials['tenant_id'] = $tenant->id;

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            // Check if user is active
            if ($user->status !== 'active') {
                Auth::logout();
                RateLimiter::hit($key, 300);
                throw ValidationException::withMessages([
                    'email' => ['Your account has been suspended. Please contact support.'],
                ]);
            }

            // Check if email is verified (if required)
            if (($tenant->settings['require_email_verification'] ?? true) && !$user->hasVerifiedEmail()) {
                Auth::logout();
                return redirect()->route('verification.notice')
                    ->with('warning', 'Please verify your email address before continuing.');
            }

            RateLimiter::clear($key);

            // Update last login
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            // Log the login
            activity()
                ->causedBy($user)
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('User logged in');

            $request->session()->regenerate();

            return redirect()->intended(route('tenant.dashboard'))
                ->with('success', 'Welcome back, ' . $user->name . '!');
        }

        RateLimiter::hit($key, 300);

        throw ValidationException::withMessages([
            'email' => ['These credentials do not match our records.'],
        ]);
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request)
    {
        $user = Auth::user();
        $tenant = tenant();

        if ($user) {
            activity()
                ->causedBy($user)
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('User logged out');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.home')
            ->with('success', 'You have been logged out successfully.');
    }

    /**
     * Show email verification notice.
     */
    public function showVerifyEmail()
    {
        $tenant = tenant();
        return view('tenant.auth.verify-email', compact('tenant'));
    }

    /**
     * Handle email verification.
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $tenant = tenant();
        $user = User::where('tenant_id', $tenant->id)->findOrFail($id);

        if (! hash_equals(sha1($user->email), $hash)) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('tenant.dashboard')
                ->with('info', 'Email already verified.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        activity()
            ->causedBy($user)
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Email verified');

        return redirect()->route('tenant.dashboard')
            ->with('success', 'Email verified successfully!');
    }

    /**
     * Send verification email.
     */
    public function sendVerificationEmail(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back()->with('info', 'Email already verified.');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'Verification link sent!');
    }

    /**
     * Show forgot password form.
     */
    public function showForgotPassword()
    {
        $tenant = tenant();
        return view('tenant.auth.forgot-password', compact('tenant'));
    }

    /**
     * Send password reset link.
     */
    public function sendResetLink(Request $request)
    {
        $tenant = tenant();
        
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)
                   ->where('tenant_id', $tenant->id)
                   ->where('status', 'active')
                   ->first();

        if (!$user) {
            return back()->with('success', 'If your email exists in our records, you will receive a password reset link.');
        }

        // Generate reset token
        $token = Str::random(60);
        
        // Store token in cache
        cache()->put(
            'password_reset_' . $tenant->id . '_' . $user->id,
            ['token' => $token, 'email' => $user->email],
            now()->addHour()
        );

        // Send email (implement your email sending logic)
        // Mail::to($user)->send(new TenantResetPasswordMail($tenant, $token));

        activity()
            ->causedBy($user)
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Password reset requested');

        return back()->with('success', 'Password reset link has been sent to your email.');
    }

    /**
     * Show reset password form.
     */
    public function showResetPassword(Request $request, $token)
    {
        $tenant = tenant();
        return view('tenant.auth.reset-password', compact('tenant', 'token'));
    }

    /**
     * Reset password.
     */
    public function resetPassword(Request $request)
    {
        $tenant = tenant();
        
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        // Find user with reset token
        $users = User::where('tenant_id', $tenant->id)->get();
        $user = null;
        
        foreach ($users as $u) {
            $resetData = cache()->get('password_reset_' . $tenant->id . '_' . $u->id);
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
        ]);

        // Clear reset token
        cache()->forget('password_reset_' . $tenant->id . '_' . $user->id);

        activity()
            ->causedBy($user)
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Password reset completed');

        return redirect()->route('login')
            ->with('success', 'Your password has been reset. Please login with your new password.');
    }

    /**
     * Validate registration request.
     */
    protected function validateRegistration(Request $request)
    {
        $tenant = tenant();
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email,NULL,id,tenant_id,' . $tenant->id
            ],
            'password' => 'required|string|min:8|confirmed',
            'terms' => 'required|accepted',
        ]);
    }

    /**
     * Validate login request.
     */
    protected function validateLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);
    }
}