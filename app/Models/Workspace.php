<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'billing_enforced' => 'boolean',
            'vacant_property_unit_amount' => 'integer',
            'rented_property_unit_amount' => 'integer',
            'billing_grace_days' => 'integer',
            'property_count' => 'integer',
            'rented_property_count' => 'integer',
            'metrics_reported_at' => 'immutable_datetime',
            'subscription_period_ends_at' => 'immutable_datetime',
            'billing_grace_ends_at' => 'immutable_datetime',
            'last_invoice_paid_at' => 'immutable_datetime',
            'stripe_sync_pending' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('is_active', 'can_sync_identity', 'is_billing_manager', 'billing_access_override')
            ->withTimestamps();
    }

    public function ssoCodes(): HasMany
    {
        return $this->hasMany(SsoCode::class);
    }

    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(WorkspaceUsageSnapshot::class);
    }

    public function vacantPropertyCount(): int
    {
        return max(0, $this->property_count - $this->rented_property_count);
    }

    public function calculatedMonthlyAmount(): int
    {
        return ($this->vacantPropertyCount() * $this->vacant_property_unit_amount)
            + ($this->rented_property_count * $this->rented_property_unit_amount);
    }

    public function subscriptionProvidesAccess(): bool
    {
        if (! $this->billing_enforced || ($this->metrics_reported_at && $this->property_count === 0)) {
            return true;
        }

        if (in_array($this->subscription_status, ['active', 'trialing'], true)) {
            return true;
        }

        return $this->billing_grace_ends_at?->isFuture() === true;
    }
}
