<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enhanced Users Table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            
            // Multi-tenant relationship
            $table->string('tenant_id', 36)->index();
            
            // Basic user information
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->index(); // Not unique globally, unique per tenant
            $table->string('username', 50)->nullable();
            $table->string('phone', 20)->nullable();
            
            // Authentication
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            
            // User status and roles
            $table->enum('status', ['active', 'inactive', 'suspended', 'pending', 'banned'])
                  ->default('pending')
                  ->index();
            $table->string('role', 50)->default('user')->index();
            $table->json('permissions')->nullable(); // Additional permissions
            $table->json('settings')->nullable(); // User preferences
            
            // Profile information
            $table->string('avatar', 500)->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();
            
            // Professional information
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('company')->nullable();
            $table->text('bio')->nullable();
            
            // Security and login tracking
            $table->timestamp('last_login_at')->nullable()->index();
            $table->string('last_login_ip', 45)->nullable();
            $table->integer('login_count')->default(0);
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            
            // Account management
            $table->string('invited_by')->nullable(); // User ID who invited this user
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('invitation_accepted_at')->nullable();
            $table->string('invitation_token', 64)->nullable();
            
            // Subscription and billing (if applicable)
            $table->string('stripe_customer_id')->nullable();
            $table->json('billing_info')->nullable();
            
            // API and integration
            $table->string('api_token', 80)->unique()->nullable();
            $table->timestamp('api_token_expires_at')->nullable();
            $table->json('integrations')->nullable(); // Third-party service connections
            
            // Compliance and privacy
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->boolean('data_processing_consent')->default(false);
            
            // Soft deletes and timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Unique constraints
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            $table->unique(['tenant_id', 'username'], 'users_tenant_username_unique');
            
            // Performance indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'role']);
            $table->index(['status', 'last_login_at']);
            $table->index(['created_at', 'tenant_id']);
        });

        // Enhanced Password Reset Tokens Table
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token', 64)->index();
            $table->string('tenant_id', 36)->nullable()->index(); // Multi-tenant support
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
            
            // Unique constraint per tenant
            $table->unique(['email', 'tenant_id', 'token'], 'password_reset_unique');
            
            // Foreign key
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Performance indexes
            $table->index(['tenant_id', 'email']);
            $table->index(['expires_at', 'used']);
        });

        // Enhanced Sessions Table
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('tenant_id', 36)->nullable()->index();
            
            // Session metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type', 50)->nullable(); // mobile, desktop, tablet
            $table->string('browser', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('location', 100)->nullable(); // City, Country
            
            // Session data and activity
            $table->longText('payload');
            $table->integer('last_activity')->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            
            // Security features
            $table->boolean('is_impersonated')->default(false);
            $table->string('impersonated_by')->nullable(); // Admin user ID
            $table->boolean('is_suspicious')->default(false);
            $table->json('security_flags')->nullable();
            
            // Foreign keys
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
                  
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Performance indexes
            $table->index(['tenant_id', 'user_id']);
            $table->index(['last_activity', 'expires_at']);
            $table->index(['user_id', 'last_activity']);
            $table->index(['is_impersonated', 'impersonated_by']);
        });

        // User Activity Log Table
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->foreignId('user_id')->index();
            
            // Activity details
            $table->string('action', 100)->index(); // login, logout, password_change, etc.
            $table->string('description')->nullable();
            $table->json('metadata')->nullable(); // Additional context
            
            // Request information
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id', 128)->nullable();
            
            // Timestamps
            $table->timestamp('created_at');
            
            // Foreign keys
            $table->foreign(['tenant_id'])
                  ->references(['id'])
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
                  
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Performance indexes
            $table->index(['tenant_id', 'user_id', 'action']);
            $table->index(['created_at', 'action']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};