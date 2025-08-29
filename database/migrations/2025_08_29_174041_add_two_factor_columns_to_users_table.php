<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     */
    protected $connection = 'central';
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            // Core 2FA fields
            $table->text('two_factor_secret')
                ->after('password')
                ->nullable();

            $table->text('two_factor_recovery_codes')
                ->after('two_factor_secret')
                ->nullable();

            $table->timestamp('two_factor_confirmed_at')
                ->after('two_factor_recovery_codes')
                ->nullable();

            // Enhanced 2FA management
            $table->boolean('two_factor_enabled')
                ->after('two_factor_confirmed_at')
                ->default(false)
                ->index();

            $table->enum('two_factor_type', ['totp', 'sms', 'email', 'backup_codes'])
                ->after('two_factor_enabled')
                ->default('totp')
                ->nullable();

            $table->string('two_factor_phone', 20)
                ->after('two_factor_type')
                ->nullable(); // For SMS 2FA

            $table->json('two_factor_backup_codes')
                ->after('two_factor_phone')
                ->nullable(); // Separate from recovery codes

            // Security and audit
            $table->integer('two_factor_recovery_codes_used')
                ->after('two_factor_backup_codes')
                ->default(0);

            $table->timestamp('two_factor_last_used_at')
                ->after('two_factor_recovery_codes_used')
                ->nullable();

            $table->integer('two_factor_failed_attempts')
                ->after('two_factor_last_used_at')
                ->default(0);

            $table->timestamp('two_factor_locked_until')
                ->after('two_factor_failed_attempts')
                ->nullable();

            // Backup and recovery
            $table->string('two_factor_recovery_email', 255)
                ->after('two_factor_locked_until')
                ->nullable(); // Alternative email for recovery

            $table->json('two_factor_trusted_devices')
                ->after('two_factor_recovery_email')
                ->nullable(); // Device fingerprints that can skip 2FA

            $table->integer('two_factor_grace_period_hours')
                ->after('two_factor_trusted_devices')
                ->default(0); // Hours before requiring 2FA again

            // Compliance and configuration
            $table->boolean('two_factor_forced_by_admin')
                ->after('two_factor_grace_period_hours')
                ->default(false);

            $table->timestamp('two_factor_setup_reminder_sent_at')
                ->after('two_factor_forced_by_admin')
                ->nullable();

            $table->json('two_factor_settings')
                ->after('two_factor_setup_reminder_sent_at')
                ->nullable(); // Custom 2FA preferences per user

            // Tenant-specific 2FA policies
            $table->boolean('two_factor_required_by_tenant')
                ->after('two_factor_settings')
                ->default(false)
                ->index();

            // Additional indexes for performance
            $table->index(['two_factor_enabled', 'two_factor_confirmed_at'], 'users_2fa_status');
            $table->index(['two_factor_locked_until'], 'users_2fa_locked');
            $table->index(['two_factor_last_used_at'], 'users_2fa_activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            // Drop all 2FA related indexes first
            $table->dropIndex('users_2fa_status');
            $table->dropIndex('users_2fa_locked');
            $table->dropIndex('users_2fa_activity');
            $table->dropIndex(['two_factor_enabled']);
            $table->dropIndex(['two_factor_required_by_tenant']);

            // Drop all 2FA columns
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_enabled',
                'two_factor_type',
                'two_factor_phone',
                'two_factor_backup_codes',
                'two_factor_recovery_codes_used',
                'two_factor_last_used_at',
                'two_factor_failed_attempts',
                'two_factor_locked_until',
                'two_factor_recovery_email',
                'two_factor_trusted_devices',
                'two_factor_grace_period_hours',
                'two_factor_forced_by_admin',
                'two_factor_setup_reminder_sent_at',
                'two_factor_settings',
                'two_factor_required_by_tenant',
            ]);
        });
    }
};