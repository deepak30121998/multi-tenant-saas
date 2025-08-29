<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            // Primary identifier - using UUID for better distribution
            $table->string('id', 36)->primary();
            
            // Basic tenant information
            $table->string('name', 255);
            $table->string('slug', 100)->unique()->index();
            
            // Domain management - support multiple domains per tenant
            $table->json('domains')->nullable();
            $table->string('primary_domain', 255)->unique()->index();
            
            // Database configuration
            $table->string('database', 64)->unique()->index();
            $table->string('db_host', 255)->nullable();
            $table->integer('db_port')->nullable();
            $table->string('db_username', 64)->nullable();
            $table->string('db_password', 255)->nullable();
            
            // Subscription and billing
            $table->string('plan', 50)->default('basic')->index();
            $table->timestamp('plan_expires_at')->nullable();
            $table->decimal('monthly_revenue', 10, 2)->default(0);
            
            // Status management
            $table->enum('status', ['active', 'inactive', 'suspended', 'pending'])->default('active')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            
            // Contact information
            $table->string('admin_email', 255)->nullable();
            $table->string('admin_name', 255)->nullable();
            $table->string('phone', 20)->nullable();
            
            // Configuration and metadata
            $table->json('settings')->nullable();
            $table->json('features')->nullable(); // Feature flags per tenant
            $table->json('limits')->nullable(); // Usage limits (users, storage, etc.)
            $table->json('metadata')->nullable(); // Custom data storage
            
            // Resource usage tracking
            $table->integer('user_count')->default(0);
            $table->bigInteger('storage_used')->default(0); // in bytes
            $table->integer('api_calls_count')->default(0);
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes(); // For soft deletion support
            
            // Additional indexes for common queries
            $table->index(['status', 'plan']);
            $table->index(['created_at']);
            $table->index(['plan_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}