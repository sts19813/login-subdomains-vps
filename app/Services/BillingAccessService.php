<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;

class BillingAccessService
{
    public function canAccess(User $user, Workspace $workspace): bool
    {
        return $this->accessReason($user, $workspace) !== 'blocked';
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->membershipFlag($user, $workspace, 'is_billing_manager');
    }

    public function accessReason(?User $user, Workspace $workspace): string
    {
        if (! $workspace->billing_enforced) {
            return 'not_enforced';
        }

        if ($workspace->metrics_reported_at && $workspace->property_count === 0) {
            return 'zero_usage';
        }

        if (in_array($workspace->subscription_status, ['active', 'trialing'], true)) {
            return 'subscription';
        }

        if ($workspace->billing_grace_ends_at?->isFuture()) {
            return 'grace';
        }

        if ($user && $this->membershipFlag($user, $workspace, 'billing_access_override')) {
            return 'override';
        }

        return 'blocked';
    }

    private function membershipFlag(User $user, Workspace $workspace, string $column): bool
    {
        return $user->workspaces()
            ->whereKey($workspace->getKey())
            ->wherePivot('is_active', true)
            ->wherePivot($column, true)
            ->exists();
    }
}
