<?php

namespace App\Http\Middleware;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves deferred session.connection after tenancy init, before StartSession.
 */
class ConfigureTenantSessionConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(ConfigureApplicationRuntime::ATTRIBUTE) !== 'tenant') {
            return $next($request);
        }

        $deferred = (bool) $request->attributes->get(ConfigureApplicationRuntime::SESSION_CONNECTION_DEFERRED);

        // Safety net: if Runtime ran before Path init and left deferral unset, re-evaluate now.
        if (! $deferred && tenancy()->initialized && config('tenancy_storage.mode') === 'database_per_tenant') {
            $deferred = $this->shouldDeferForCurrentTenant();
        }

        if (! $deferred) {
            return $next($request);
        }

        if (! tenancy()->initialized) {
            abort(500, 'Tenancy must be initialized before deferred session connection is resolved.');
        }

        $tenant = tenancy()->tenant;

        if (! $tenant) {
            abort(500, 'Tenancy initialized without a tenant context.');
        }

        $resolver = app(TenantStorageResolver::class);
        $connection = $resolver->connectionFor($tenant);
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');

        if ($connection === $sharedConnection) {
            config(['session.connection' => $connection]);
            $this->forgetSessionInstances();

            return $next($request);
        }

        if (! config("database.connections.{$connection}")) {
            abort(500, "Tenant database connection [{$connection}] is not registered.");
        }

        config(['session.connection' => $connection]);
        $this->forgetSessionInstances();

        if (config('session.connection') !== $connection) {
            abort(500, 'Failed to bind session connection for tenant storage.');
        }

        return $next($request);
    }

    private function shouldDeferForCurrentTenant(): bool
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
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

    private function forgetSessionInstances(): void
    {
        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }

        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }
}
