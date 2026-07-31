<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * BK-108 — scan Tenant Host/Path routes for leftover addressing → scalar injection.
 */
trait ScansTenantRouteControllerPositionalBinding
{
    /**
     * Route name => Owner-reviewed reason. Keep empty unless explicitly frozen.
     *
     * @var array<string, string>
     */
    private const BK108_POSITIONAL_BINDING_ALLOWLIST = [];

    /** @var list<string> */
    private const BK108_TENANT_ADDRESSING_PARAMS = ['tenant_label', 'tenant'];

    /**
     * @return list<array{route: string, action: string, detail: string}>
     */
    protected function scanTenantAddressedControllerRoutes(): array
    {
        $violations = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $routeParams = $route->parameterNames();
            $addressing = array_values(array_intersect($routeParams, self::BK108_TENANT_ADDRESSING_PARAMS));
            if ($addressing === []) {
                continue;
            }

            $action = $route->getAction('controller');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);
            $name = (string) ($route->getName() ?: $action);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                $violations[] = [
                    'route' => $name,
                    'action' => $action,
                    'detail' => 'controller action missing',
                ];

                continue;
            }

            if (isset(self::BK108_POSITIONAL_BINDING_ALLOWLIST[$name])) {
                continue;
            }

            $ref = new ReflectionMethod($class, $method);
            $scalarParams = [];
            $controllerParamNames = [];

            foreach ($ref->getParameters() as $parameter) {
                $controllerParamNames[] = $parameter->getName();
                if ($this->bk108IsInjectableScalar($parameter)) {
                    $scalarParams[] = $parameter->getName();
                }
            }

            foreach ($scalarParams as $scalarName) {
                if (! in_array($scalarName, $routeParams, true)) {
                    $violations[] = [
                        'route' => $name,
                        'action' => $action,
                        'detail' => "scalar \${$scalarName} is not a declared route parameter",
                    ];
                }
            }

            $unmatchedRouteParams = array_values(array_diff($routeParams, $controllerParamNames));
            $leftoverAddressing = array_values(array_intersect($unmatchedRouteParams, self::BK108_TENANT_ADDRESSING_PARAMS));

            if ($leftoverAddressing !== [] && $scalarParams !== []) {
                $violations[] = [
                    'route' => $name,
                    'action' => $action,
                    'detail' => sprintf(
                        'leftover addressing {%s} can inject into scalar(s) [%s]; read named values from Request or drop scalars',
                        implode(',', $leftoverAddressing),
                        implode(',', $scalarParams)
                    ),
                ];
            }
        }

        return $violations;
    }

    protected function bk108IsInjectableScalar(ReflectionParameter $parameter): bool
    {
        if ($parameter->getAttributes() !== []) {
            return false;
        }

        $type = $parameter->getType();
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && ! $inner->isBuiltin() && $inner->getName() !== 'null') {
                    return false;
                }
            }

            return true;
        }

        if (! $type instanceof ReflectionNamedType) {
            return true;
        }

        if (! $type->isBuiltin()) {
            return false;
        }

        return in_array($type->getName(), ['string', 'int', 'float', 'bool'], true);
    }

    /**
     * @param  list<array{route: string, action: string, detail: string}>  $violations
     */
    protected function formatBk108PositionalViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        return implode("\n", array_map(
            static fn (array $v): string => sprintf('- %s (%s): %s', $v['route'], $v['action'], $v['detail']),
            $violations
        ));
    }
}
