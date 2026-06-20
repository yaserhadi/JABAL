<?php

namespace App\Http\Middleware;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects platform vs tenant session runtime before StartSession (ADR-0007 §3.1.3.1).
 *
 * Stage 5B: defers session.connection on database_per_tenant paths until tenancy init
 * (see ConfigureTenantSessionConnection).
 */
class ConfigureApplicationRuntime
{
    public const ATTRIBUTE = 'application_runtime';

    public const SESSION_CONNECTION_DEFERRED = 'session_connection_deferred';

    public function handle(Request $request, Closure $next, ?string $profile = null): Response
    {
        $profile = $profile ?? $this->resolveProfile($request);

        $config = config("session.profiles.{$profile}");

        if (! is_array($config)) {
            abort(500, "Unknown application runtime profile [{$profile}].");
        }

        $deferConnection = $profile === 'tenant' && $this->shouldDeferSessionConnection($request);

        config([
            'session.table' => $config['table'],
            'session.cookie' => $config['cookie'],
        ]);

        if (! $deferConnection) {
            config(['session.connection' => $config['connection']]);
        }

        $this->forgetSessionInstances();

        $request->attributes->set(self::ATTRIBUTE, $profile);
        $request->attributes->set(self::SESSION_CONNECTION_DEFERRED, $deferConnection);

        return $next($request);
    }

    private function shouldDeferSessionConnection(Request $request): bool
    {
        if (config('tenancy_storage.mode') !== 'database_per_tenant') {
            return false;
        }

        $tenant = $this->resolveTenantForDeferralDecision($request);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $resolver = app(TenantStorageResolver::class);
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');

        try {
            return $resolver->connectionFor($tenant) !== $sharedConnection;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveTenantForDeferralDecision(Request $request): ?Tenant
    {
        if ($request->segment(1) !== 't') {
            return null;
        }

        $id = $request->segment(2);

        if (! $id || ! Str::isUuid($id)) {
            return null;
        }

        return Tenant::query()->find($id);
    }

    private function forgetSessionInstances(): void
    {
        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }

        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }

    private function resolveProfile(Request $request): string
    {
        if ($request->is('platform', 'platform/*')) {
            return 'platform';
        }

        return 'tenant';
    }
}
