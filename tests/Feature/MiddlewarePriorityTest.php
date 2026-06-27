<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureApplicationRuntime;
use App\Http\Middleware\ConfigureTenantSessionConnection;
use App\Http\Middleware\InitializeTenancyByPathWhenApplicable;
use App\Http\Middleware\InitializeTenancyFromAuthRequest;
use App\Http\Middleware\InitializeTenancyFromSession;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

class MiddlewarePriorityTest extends TestCase
{
    public function test_session_middleware_ordering_invariant(): void
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $priority = $kernel->getMiddlewarePriority();
        $index = fn (string $class): int|false => array_search($class, $priority, true);

        $classes = [
            ConfigureApplicationRuntime::class,
            InitializeTenancyByPathWhenApplicable::class,
            InitializeTenancyFromSession::class,
            InitializeTenancyFromAuthRequest::class,
            ConfigureTenantSessionConnection::class,
            StartSession::class,
        ];

        foreach ($classes as $class) {
            $this->assertNotFalse($index($class), "Missing middleware in priority list: {$class}");
        }

        for ($i = 0; $i < count($classes) - 1; $i++) {
            $this->assertLessThan(
                $index($classes[$i + 1]),
                $index($classes[$i]),
                "{$classes[$i]} must run before {$classes[$i + 1]}"
            );
        }
    }
}
