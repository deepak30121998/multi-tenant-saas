<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The database connection that should be used by the model.
     */
    protected $connection = 'central';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'is_admin',
        'is_super_admin',
        'last_login_at',
        'avatar',
        'status',
        'role',
        'must_change_password',
        
        // 2FA fields
        'two_factor_enabled',
        'two_factor_type',
        'two_factor_phone',
        'two_factor_recovery_codes_used',
        'two_factor_failed_attempts',
        'two_factor_grace_period_hours',
        'two_factor_forced_by_admin',
        'two_factor_required_by_tenant',
        'two_factor_recovery_email',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_backup_codes',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            
            // 2FA casts
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_at' => 'datetime',
            'two_factor_locked_until' => 'datetime',
            'two_factor_setup_reminder_sent_at' => 'datetime',
            'two_factor_forced_by_admin' => 'boolean',
            'two_factor_required_by_tenant' => 'boolean',
            
            // JSON fields
            'two_factor_backup_codes' => 'array',
            'two_factor_trusted_devices' => 'array',
            'two_factor_settings' => 'array',
        ];
    }

    /**
     * Tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Check if user is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin || $this->hasRole('super_admin');
    }

    /**
     * Check if user is an admin (tenant admin)
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->hasRole('admin');
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if 2FA is enabled and confirmed
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && !is_null($this->two_factor_confirmed_at);
    }

    /**
     * Check if 2FA is required for this user
     */
    public function requiresTwoFactor(): bool
    {
        return $this->two_factor_forced_by_admin || 
               $this->two_factor_required_by_tenant ||
               $this->hasTwoFactorEnabled();
    }

    /**
     * Check if user is currently locked due to 2FA failures
     */
    public function isTwoFactorLocked(): bool
    {
        return $this->two_factor_locked_until && 
               $this->two_factor_locked_until->isFuture();
    }

    /**
     * Get user's display name
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->email;
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for users with 2FA enabled
     */
    public function scopeWithTwoFactor($query)
    {
        return $query->where('two_factor_enabled', true)
                    ->whereNotNull('two_factor_confirmed_at');
    }

    /**
     * Scope for super admins
     */
    public function scopeSuperAdmins($query)
    {
        return $query->where('is_super_admin', true);
    }

    /**
     * Scope for tenant users
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            // Set default status if not provided
            if (empty($user->status)) {
                $user->status = 'active';
            }
            
            // Auto-verify super admin emails
            if ($user->is_super_admin && !$user->email_verified_at) {
                $user->email_verified_at = now();
            }
        });

        static::updating(function ($user) {
            // Update last login when user becomes active
            if ($user->isDirty('status') && $user->status === 'active') {
                $user->last_login_at = now();
            }
        });
    }
}