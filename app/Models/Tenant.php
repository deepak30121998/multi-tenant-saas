<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Custom columns for tenant table
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug', 
            'primary_domain',
            'plan',
            'status',
            'admin_email',
            'admin_name',
            'settings',
            'features',
            'limits',
            'user_count',
            'storage_used',
            'activated_at',
        ];
    }

    /**
     * Custom attributes casting
     */
    protected $casts = [
        'settings' => 'array',
        'features' => 'array', 
        'limits' => 'array',
        'activated_at' => 'datetime',
        'user_count' => 'integer',
        'storage_used' => 'integer',
    ];

    /**
     * Get tenant admin user
     */
    public function getAdminUser()
    {
        return $this->run(function () {
            return \App\Models\User::where('role', 'admin')->first();
        });
    }

    /**
     * Check if tenant has feature enabled
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }

    /**
     * Check if tenant is within limits
     */
    public function isWithinLimit(string $limit, int $current): bool
    {
        $maxLimit = $this->limits[$limit] ?? null;
        return $maxLimit === null || $current <= $maxLimit;
    }
}