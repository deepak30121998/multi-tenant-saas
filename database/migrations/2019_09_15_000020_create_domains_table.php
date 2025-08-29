<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDomainsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            
            // Domain information
            $table->string('domain', 255)->unique()->index();
            $table->string('subdomain', 100)->nullable()->index();
            $table->enum('type', ['primary', 'custom', 'subdomain'])->default('subdomain')->index();
            
            // Tenant relationship
            $table->string('tenant_id', 36)->index();
            
            // Domain status and verification
            $table->enum('status', ['active', 'inactive', 'pending_verification', 'failed_verification'])
                  ->default('pending_verification')
                  ->index();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable();
            $table->text('verification_method')->nullable(); // DNS, file, etc.
            
            // SSL/TLS configuration
            $table->boolean('ssl_enabled')->default(false);
            $table->timestamp('ssl_expires_at')->nullable();
            $table->enum('ssl_status', ['active', 'expired', 'pending', 'failed'])->nullable();
            
            // Redirect and alias configuration
            $table->boolean('is_primary')->default(false);
            $table->string('redirect_to', 255)->nullable();
            $table->integer('redirect_type')->nullable(); // 301, 302
            
            // DNS and technical details
            $table->json('dns_records')->nullable(); // Store DNS configuration
            $table->string('certificate_path', 500)->nullable();
            $table->json('metadata')->nullable(); // Additional domain-specific settings
            
            // Usage tracking
            $table->bigInteger('traffic_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraint
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            
            // Additional indexes for performance
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_primary']);
            $table->index(['status', 'verified_at']);
            $table->index(['ssl_enabled', 'ssl_expires_at']);
            
            // Unique constraint: only one primary domain per tenant
            $table->unique(['tenant_id', 'is_primary'], 'unique_primary_domain');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
}