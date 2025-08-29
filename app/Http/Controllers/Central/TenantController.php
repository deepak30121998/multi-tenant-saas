<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class TenantController extends Controller
{
    /**
     * Display tenant registration form.
     */
    public function showRegistration()
    {
        return view('central.tenant.register');
    }

    /**
     * Handle tenant registration.
     */
    public function register(Request $request)
    {
        $validator = $this->validateTenantRegistration($request);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();

        try {
            // Generate unique tenant ID and database name
            $tenantId = Str::uuid();
            $slug = Str::slug($request->company_name);
            $domain = $slug . '.localhost'; // In production, use your actual domain
            $database = 'tenant_' . $slug . '_' . time();

            // Create tenant
            $tenant = Tenant::create([
                'id' => $tenantId,
                'name' => $request->company_name,
                'slug' => $slug,
                'primary_domain' => $domain,
                'database' => $database,
                'admin_email' => $request->email,
                'admin_name' => $request->name,
                'status' => 'pending',
                'plan' => $request->plan ?? 'basic',
                'settings' => [
                    'allow_registration' => true,
                    'require_email_verification' => true,
                    'timezone' => $request->timezone ?? 'UTC',
                ],
                'features' => $this->getDefaultFeatures($request->plan ?? 'basic'),
                'limits' => $this->getDefaultLimits($request->plan ?? 'basic'),
            ]);

            // Create tenant database
            $this->createTenantDatabase($database);

            // Create tenant admin user
            $adminUser = $this->createTenantAdmin($tenant, $request);

            // Send welcome email (implement as needed)
            // Mail::to($adminUser)->send(new TenantWelcomeMail($tenant));

            DB::commit();

            // Log the registration
            activity()
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('New tenant registered');

            return redirect()->route('tenant.register.success', ['tenant' => $tenant->id])
                ->with('success', 'Tenant registered successfully! Check your email for setup instructions.');

        } catch (\Exception $e) {
            DB::rollback();
            
            return back()->withErrors([
                'error' => 'Tenant registration failed. Please try again.'
            ])->withInput();
        }
    }

    /**
     * Show registration success page.
     */
    public function registrationSuccess(Request $request)
    {
        $tenantId = $request->route('tenant');
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            abort(404);
        }

        return view('central.tenant.registration-success', compact('tenant'));
    }

    /**
     * Verify tenant email.
     */
    public function verifyTenant($token)
    {
        // Find tenant by verification token
        $tenant = Tenant::where('verification_token', $token)->first();

        if (!$tenant) {
            abort(404, 'Invalid verification token.');
        }

        if ($tenant->status === 'active') {
            return redirect()->away('http://' . $tenant->primary_domain)
                ->with('info', 'Tenant already verified.');
        }

        // Activate tenant
        $tenant->update([
            'status' => 'active',
            'activated_at' => now(),
            'verification_token' => null,
        ]);

        // Run tenant migrations
        $this->runTenantMigrations($tenant);

        activity()
            ->withProperties(['tenant_id' => $tenant->id])
            ->log('Tenant verified and activated');

        return redirect()->away('http://' . $tenant->primary_domain . '/login')
            ->with('success', 'Tenant verified successfully! You can now login.');
    }

    /**
     * Check domain/slug availability.
     */
    public function checkAvailability(Request $request)
    {
        $request->validate([
            'slug' => 'required|string|max:100',
        ]);

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
     * Validate tenant registration.
     */
    protected function validateTenantRegistration(Request $request)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:tenants,admin_email',
            'company_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'plan' => 'required|in:basic,pro,enterprise',
            'timezone' => 'nullable|string|max:50',
            'terms' => 'required|accepted',
        ]);
    }

    /**
     * Create tenant database.
     */
    protected function createTenantDatabase($database)
    {
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /**
     * Create tenant admin user.
     */
    protected function createTenantAdmin($tenant, $request)
    {
        // Generate random password
        $password = Str::random(12);

        // Switch to tenant database
        tenancy()->initialize($tenant);

        try {
            // Create admin user in tenant database
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Create admin role and assign
            $adminRole = Role::create([
                'name' => 'admin',
                'guard_name' => 'web',
            ]);

            $user->assignRole($adminRole);

            // Store password in cache for email (or send directly)
            cache()->put(
                'tenant_admin_password_' . $tenant->id,
                $password,
                now()->addHours(24)
            );

            return $user;

        } finally {
            tenancy()->end();
        }
    }

    /**
     * Run tenant migrations.
     */
    protected function runTenantMigrations($tenant)
    {
        try {
            tenancy()->initialize($tenant);
            
            // Run tenant-specific migrations
            \Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            // Seed basic data if needed
            \Artisan::call('db:seed', [
                '--database' => 'tenant',
                '--class' => 'TenantSeeder',
                '--force' => true,
            ]);

        } finally {
            tenancy()->end();
        }
    }

    /**
     * Get default features for plan.
     */
    protected function getDefaultFeatures($plan)
    {
        $features = [
            'basic' => [
                'users' => true,
                'teams' => false,
                'api_access' => false,
                'custom_domain' => false,
                'priority_support' => false,
                'advanced_analytics' => false,
            ],
            'pro' => [
                'users' => true,
                'teams' => true,
                'api_access' => true,
                'custom_domain' => false,
                'priority_support' => false,
                'advanced_analytics' => true,
            ],
            'enterprise' => [
                'users' => true,
                'teams' => true,
                'api_access' => true,
                'custom_domain' => true,
                'priority_support' => true,
                'advanced_analytics' => true,
            ],
        ];

        return $features[$plan] ?? $features['basic'];
    }

    /**
     * Get default limits for plan.
     */
    protected function getDefaultLimits($plan)
    {
        $limits = [
            'basic' => [
                'users' => 5,
                'storage' => 1024 * 1024 * 1024, // 1GB in bytes
                'api_calls' => 1000,
                'projects' => 3,
            ],
            'pro' => [
                'users' => 25,
                'storage' => 10 * 1024 * 1024 * 1024, // 10GB
                'api_calls' => 10000,
                'projects' => 15,
            ],
            'enterprise' => [
                'users' => -1, // unlimited
                'storage' => -1, // unlimited
                'api_calls' => -1, // unlimited
                'projects' => -1, // unlimited
            ],
        ];

        return $limits[$plan] ?? $limits['basic'];
    }

    /**
     * Stripe webhook handler.
     */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $endpoint_secret = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $endpoint_secret);
        } catch (\Exception $e) {
            return response('Invalid signature', 400);
        }

        // Handle different event types
        switch ($event['type']) {
            case 'invoice.payment_succeeded':
                $this->handleSuccessfulPayment($event['data']['object']);
                break;
            case 'invoice.payment_failed':
                $this->handleFailedPayment($event['data']['object']);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionCancelled($event['data']['object']);
                break;
        }

        return response('Success', 200);
    }

    /**
     * Handle successful payment.
     */
    protected function handleSuccessfulPayment($invoice)
    {
        // Find tenant by customer ID
        $tenant = Tenant::where('stripe_customer_id', $invoice['customer'])->first();
        
        if ($tenant) {
            $tenant->update([
                'status' => 'active',
                'plan_expires_at' => now()->addMonth(),
            ]);

            activity()
                ->withProperties(['tenant_id' => $tenant->id, 'invoice_id' => $invoice['id']])
                ->log('Payment successful');
        }
    }

    /**
     * Handle failed payment.
     */
    protected function handleFailedPayment($invoice)
    {
        $tenant = Tenant::where('stripe_customer_id', $invoice['customer'])->first();
        
        if ($tenant) {
            // Send notification email
            // Mark tenant for suspension after grace period
            
            activity()
                ->withProperties(['tenant_id' => $tenant->id, 'invoice_id' => $invoice['id']])
                ->log('Payment failed');
        }
    }

    /**
     * Handle subscription cancellation.
     */
    protected function handleSubscriptionCancelled($subscription)
    {
        $tenant = Tenant::where('stripe_subscription_id', $subscription['id'])->first();
        
        if ($tenant) {
            $tenant->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspension_reason' => 'Subscription cancelled',
            ]);

            activity()
                ->withProperties(['tenant_id' => $tenant->id])
                ->log('Subscription cancelled');
        }
    }
}