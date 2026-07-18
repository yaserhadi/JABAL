<?php

namespace App\Http\Middleware;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects platform vs tenant session runtime before StartSession (ADR-0007 §3.1.3.1).
 *
 * BK-073: consumes RequestHostClassifier + tenancy()->tenant — NO independent Host→Tenant lookup.
 * Runs in Phase 2 AFTER Tenant resolution and operational gate.
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

        // Always reset to profile baseline; dedicated binding happens in ConfigureTenantSessionConnection when deferred.
        config(['session.connection' => $config['connection']]);

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

    /**
     * Prefer already-resolved tenancy()->tenant (BK-073 Phase 1). Fall back to path/auth
     * hints only when tenancy is not yet initialized (Path profile legacy surfaces).
     */
    private function resolveTenantForDeferralDecision(Request $request): ?Tenant
    {
        if (tenancy()->initialized && tenancy()->tenant instanceof Tenant) {
            return tenancy()->tenant;
        }

        if ($request->segment(1) === 't') {
            $key = $request->segment(2);

            if (! is_string($key) || $key === '') {
                return null;
            }

            if (Str::isUuid($key)) {
                return Tenant::query()->find($key);
            }

            return Tenant::query()->where('slug', $key)->first();
        }

        if ($this->isEligibleAuthPostForDeferral($request)) {
            return $this->resolveTenantFromAuthEmail($request);
        }

        if ($request->is('auth/sso/callback')) {
            return $this->resolveTenantFromSsoCallbackState($request);
        }

        return null;
    }

    private function resolveTenantFromSsoCallbackState(Request $request): ?Tenant
    {
        $state = $request->query('state');

        if (! is_string($state) || $state === '') {
            return null;
        }

        $tenantId = \Modules\Identity\Support\Sso\SsoAuthorizationState::tenantIdFromStateParameter($state);

        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }

    private function isEligibleAuthPostForDeferral(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        if ($request->is('platform', 'platform/*', 'api', 'api/*')) {
            return false;
        }

        if ($request->segment(1) === 't') {
            return false;
        }

        $path = $request->path();

        if (str_starts_with($path, 'password')) {
            return false;
        }

        return in_array($path, ['login', 'register'], true);
    }

    private function resolveTenantFromAuthEmail(Request $request): ?Tenant
    {
        $email = $request->input('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        $tenantUser = TenantUser::findForLogin($email);

        if (! $tenantUser) {
            return null;
        }

        return Tenant::query()->find($tenantUser->tenant_id);
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

        $class = RequestHostClassifier::classOf($request);

        // Reserved infrastructure hosts (API/asset/operations) never use tenant session.
        if (in_array($class, [
            RequestHostClassifier::CLASS_API,
            RequestHostClassifier::CLASS_ASSET,
            RequestHostClassifier::CLASS_OPERATIONS,
        ], true)) {
            return 'platform';
        }

        return 'tenant';
    }
}
