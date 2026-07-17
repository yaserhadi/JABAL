<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureApplicationRuntime;
use App\Http\Middleware\ConfigureTenantSessionConnection;
use App\Http\Middleware\EnsureResolvedTenantIsOperational;
use App\Http\Middleware\InitializeTenancyByHostWhenApplicable;
use App\Http\Middleware\InitializeTenancyByPathWhenApplicable;
use App\Http\Middleware\InitializeTenancyFromAuthRequest;
use App\Http\Middleware\InitializeTenancyFromSession;
use App\Http\Middleware\InitializeTenancyFromSsoState;
use App\Http\Middleware\RejectTenancyContextConflict;
use App\Http\Middleware\RequestHostClassifier;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

class MiddlewarePriorityTest extends TestCase
{
    public function test_session_middleware_ordering_invariant(): void
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $group = $kernel->getMiddlewareGroups()['web'] ?? [];

        $indexInGroup = function (string $class) use ($group): int {
            $i = array_search($class, $group, true);
            $this->assertNotFalse($i, "Missing middleware in web group: {$class}");

            return (int) $i;
        };

        // Phase 1 relative order inside the web group (prepended block).
        $phase1 = [
            RequestHostClassifier::class,
            InitializeTenancyByHostWhenApplicable::class,
            InitializeTenancyByPathWhenApplicable::class,
            EnsureResolvedTenantIsOperational::class,
            ConfigureApplicationRuntime::class,
            InitializeTenancyFromAuthRequest::class,
            InitializeTenancyFromSsoState::class,
            ConfigureTenantSessionConnection::class,
        ];

        for ($i = 0; $i < count($phase1) - 1; $i++) {
            $this->assertLessThan(
                $indexInGroup($phase1[$i + 1]),
                $indexInGroup($phase1[$i]),
                "{$phase1[$i]} must appear before {$phase1[$i + 1]} in the web group"
            );
        }

        // Priority list (SortedMiddleware authority): Phase 1 before StartSession; Phase 2 after.
        $priority = $kernel->getMiddlewarePriority();
        $index = fn (string $class): int|false => array_search($class, $priority, true);

        foreach ($phase1 as $class) {
            $this->assertNotFalse($index($class), "Missing from priority map: {$class}");
        }

        for ($i = 0; $i < count($phase1) - 1; $i++) {
            $this->assertLessThan(
                $index($phase1[$i + 1]),
                $index($phase1[$i]),
                "{$phase1[$i]} must precede {$phase1[$i + 1]} in the priority map"
            );
        }

        $this->assertLessThan(
            $index(StartSession::class),
            $index(ConfigureTenantSessionConnection::class),
            'ConfigureTenantSessionConnection must run before StartSession'
        );

        $this->assertNotFalse($index(RejectTenancyContextConflict::class));
        $this->assertNotFalse($index(InitializeTenancyFromSession::class));
        $this->assertLessThan(
            $index(RejectTenancyContextConflict::class),
            $index(StartSession::class),
            'StartSession must run before RejectTenancyContextConflict'
        );
        $this->assertLessThan(
            $index(InitializeTenancyFromSession::class),
            $index(RejectTenancyContextConflict::class),
            'RejectTenancyContextConflict must run before InitializeTenancyFromSession'
        );

        // SortedMiddleware must not move SessionConnection/StartSession ahead of Path/Runtime.
        $sorted = (new \Illuminate\Routing\SortedMiddleware($priority, $group))->all();
        $sortedIndex = function (string $class) use ($sorted): int {
            foreach ($sorted as $i => $middleware) {
                if (is_string($middleware) && $middleware === $class) {
                    return (int) $i;
                }
            }
            $this->fail("Missing from sorted web stack: {$class}");
        };

        $this->assertLessThan(
            $sortedIndex(ConfigureTenantSessionConnection::class),
            $sortedIndex(InitializeTenancyByPathWhenApplicable::class),
            'Path init must run before ConfigureTenantSessionConnection after sorting'
        );
        $this->assertLessThan(
            $sortedIndex(ConfigureTenantSessionConnection::class),
            $sortedIndex(ConfigureApplicationRuntime::class),
            'ConfigureApplicationRuntime must run before ConfigureTenantSessionConnection after sorting'
        );
        $this->assertLessThan(
            $sortedIndex(StartSession::class),
            $sortedIndex(ConfigureTenantSessionConnection::class),
            'ConfigureTenantSessionConnection must run before StartSession after sorting'
        );
    }
}
