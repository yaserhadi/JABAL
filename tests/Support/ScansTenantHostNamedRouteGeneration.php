<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Finder\Finder;

/**
 * BK-109 — server-side Tenant Host named-route generation contract.
 *
 * Combines Laravel route metadata + product source scan + frozen inventory discipline.
 * Default allowlist is empty.
 */
trait ScansTenantHostNamedRouteGeneration
{
    /**
     * route name => Owner-reviewed reason. Keep empty unless explicitly frozen.
     *
     * @var array<string, string>
     */
    private const BK109_NAMED_ROUTE_ALLOWLIST = [];

    /**
     * Host-addressed routes that require tenant_label in generation.
     *
     * @return list<string>
     */
    protected function bk109HostRoutesRequiringTenantLabel(): array
    {
        $names = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $name = $route->getName();
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (! in_array('tenant_label', $route->parameterNames(), true)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Bare redirect()->route('…') / ->route('…') calls that omit tenant_label
     * for Host routes that require it.
     *
     * @return list<array{file: string, line: int, route: string, snippet: string}>
     */
    protected function bk109ScanBareTenantHostNamedRouteRedirects(): array
    {
        $hostRoutes = array_fill_keys($this->bk109HostRoutesRequiringTenantLabel(), true);
        $violations = [];

        $finder = (new Finder)
            ->files()
            ->in([
                base_path('app'),
                base_path('Modules'),
            ])
            ->name('*.php')
            ->notPath('vendor');

        foreach ($finder as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            $lines = file($path) ?: [];

            foreach ($lines as $idx => $line) {
                $window = implode('', array_slice($lines, max(0, $idx - 2), 6));
                // Only bare redirect()->route(...) generators (BK-109 defect class).
                if (! preg_match('/redirect\s*\(\s*\)\s*->\s*route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $window, $m)) {
                    continue;
                }

                $routeName = $m[1];
                if (! isset($hostRoutes[$routeName])) {
                    continue;
                }

                if (isset(self::BK109_NAMED_ROUTE_ALLOWLIST[$routeName])) {
                    continue;
                }

                if (str_contains($window, 'namedRouteUrl')) {
                    continue;
                }
                if (preg_match('/[\'"]tenant_label[\'"]\\s*=>/', $window)) {
                    continue;
                }

                $violations[] = [
                    'file' => $relative,
                    'line' => $idx + 1,
                    'route' => $routeName,
                    'snippet' => trim($line),
                ];
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    protected function bk109ProductPhpPaths(): array
    {
        return [
            base_path('app'),
            base_path('Modules'),
        ];
    }
}
