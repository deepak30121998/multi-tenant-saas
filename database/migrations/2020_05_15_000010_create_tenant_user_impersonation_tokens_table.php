<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantUserImpersonationTokensTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenant_user_impersonation_tokens', function (Blueprint $table) {
            // Primary token identifier (hashed for security)
            $table->string('token', 128)->primary();
            
            // Tenant and user relationships
            $table->string('tenant_id', 36)->index();
            $table->unsignedBigInteger('target_user_id')->index(); // User being impersonated
            $table->unsignedBigInteger('impersonator_id')->index(); // Admin/user doing the impersonation
            
            // Authentication and authorization
            $table->string('auth_guard', 50)->default('web');
            $table->json('permissions')->nullable(); // Specific permissions during impersonation
            $table->json('restrictions')->nullable(); // What the impersonator cannot do
            
            // Session and redirect management
            $table->string('redirect_url', 500)->nullable();
            $table->string('return_url', 500)->nullable(); // Where to return after impersonation ends
            $table->string('session_id', 128)->nullable()->index();
            $table->string('ip_address', 45)->nullable(); // Support IPv6
            $table->text('user_agent')->nullable();
            
            // Token lifecycle management
            $table->enum('status', ['active', 'used', 'expired', 'revoked'])->default('active')->index();
            $table->timestamp('expires_at')->index(); // Token expiration
            $table->timestamp('used_at')->nullable(); // When token was consumed
            $table->timestamp('revoked_at')->nullable(); // When token was revoked
            $table->string('revoked_by')->nullable(); // Who revoked the token
            $table->text('revoke_reason')->nullable();
            
            // Security and audit
            $table->integer('max_uses')->default(1); // How many times token can be used
            $table->integer('used_count')->default(0);
            $table->boolean('single_use')->default(true); // Token becomes invalid after first use
            $table->integer('max_duration_minutes')->nullable(); // Max impersonation session length
            $table->json('allowed_actions')->nullable(); // Specific actions allowed during impersonation
            
            // Audit trail
            $table->string('created_by')->nullable(); // Who created the impersonation token
            $table->text('reason')->nullable(); // Reason for impersonation
            $table->json('context')->nullable(); // Additional context/metadata
            $table->json('audit_log')->nullable(); // Actions taken during impersonation
            
            // Timestamps
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            
            // Foreign key constraints
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Indexes for performance and common queries
            $table->index(['tenant_id', 'target_user_id']);
            $table->index(['tenant_id', 'impersonator_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['created_at', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at'], 'tenant_active_tokens');
            
            // Compound index for cleanup operations
            $table->index(['status', 'expires_at', 'created_at'], 'cleanup_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_user_impersonation_tokens');
    }
}