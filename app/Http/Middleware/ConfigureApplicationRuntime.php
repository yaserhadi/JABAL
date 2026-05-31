<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects platform vs tenant session runtime before StartSession (ADR-0007 §3.1.3.1).
 */
class ConfigureApplicationRuntime
{
    public const ATTRIBUTE = 'application_runtime';

    public function handle(Request $request, Closure $next, ?string $profile = null): Response
    {
        $profile = $profile ?? $this->resolveProfile($request);

        $config = config("session.profiles.{$profile}");

        if (! is_array($config)) {
            abort(500, "Unknown application runtime profile [{$profile}].");
        }

        config([
            'session.connection' => $config['connection'],
            'session.table' => $config['table'],
            'session.cookie' => $config['cookie'],
        ]);

        // Session manager may already be resolved with the CLI/fallback connection.
        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }

        $request->attributes->set(self::ATTRIBUTE, $profile);

        return $next($request);
    }

    private function resolveProfile(Request $request): string
    {
        if ($request->is('platform', 'platform/*')) {
            return 'platform';
        }

        return 'tenant';
    }
}
