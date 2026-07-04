<?php

namespace Modules\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\UserSession;
use Modules\Tenancy\Models\Tenant;

class SessionRegistryService
{
    public function register(TenantUser $user, Request $request, ?string $sessionId): UserSession
    {
        return UserSession::create([
            'tenant_id' => $user->tenant_id ?? tenancy()->tenant?->id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_label' => $this->parseDeviceLabel($request->userAgent() ?? ''),
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);
    }

    public function touch(string $sessionId): void
    {
        UserSession::where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }

    public function revoke(string $userSessionId): void
    {
        UserSession::where('id', $userSessionId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(TenantUser $user, ?string $exceptSessionId = null): int
    {
        $query = UserSession::forUser($user->id)->active();

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->update(['revoked_at' => now()]);
    }

    public function listForUser(TenantUser $user): Collection
    {
        return UserSession::forUser($user->id)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();
    }

    public function listForCurrentTenantUser(TenantUser $user, Tenant $tenant): Collection
    {
        return UserSession::forUser($user->id)
            ->where('tenant_id', $tenant->id)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();
    }

    public function revokeForCurrentTenantUser(TenantUser $user, Tenant $tenant, string $userSessionId): void
    {
        $record = UserSession::forUser($user->id)
            ->where('tenant_id', $tenant->id)
            ->where('id', $userSessionId)
            ->active()
            ->first();

        if (! $record) {
            abort(404);
        }

        $record->update(['revoked_at' => now()]);
    }

    public function revokeOtherSessionsForCurrentTenantUser(TenantUser $user, Tenant $tenant, string $currentLaravelSessionId): int
    {
        $query = UserSession::forUser($user->id)
            ->where('tenant_id', $tenant->id)
            ->active();

        $currentRecord = (clone $query)
            ->where('session_id', $currentLaravelSessionId)
            ->first();

        if ($currentRecord) {
            $query->where('id', '!=', $currentRecord->id);
        } else {
            $query->where('session_id', '!=', $currentLaravelSessionId);
        }

        return $query->update(['revoked_at' => now()]);
    }

    public function cleanup(int $olderThanDays): int
    {
        return UserSession::where(function ($query) use ($olderThanDays) {
            $query->whereNotNull('revoked_at')
                ->where('revoked_at', '<', now()->subDays($olderThanDays));
        })->orWhere(function ($query) use ($olderThanDays) {
            $query->where('last_activity_at', '<', now()->subDays($olderThanDays));
        })->delete();
    }

    public function isRevoked(string $sessionId): bool
    {
        $record = UserSession::where('session_id', $sessionId)->first();

        if (! $record) {
            return false;
        }

        return $record->revoked_at !== null;
    }

    protected function parseDeviceLabel(string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown';
        }

        $browser = 'Unknown Browser';
        $os = 'Unknown OS';

        if (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Edg')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Safari')) {
            $browser = 'Safari';
        }

        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        }

        return "{$browser} on {$os}";
    }
}
