<?php

namespace Modules\Identity\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Billing\Events\SubscriptionPlanChanged;
use Modules\Identity\Listeners\DeregisterSessionOnLogout;
use Modules\Identity\Listeners\DisableSsoOnEntitlementLoss;
use Modules\Identity\Listeners\RegisterSessionOnLogin;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        Login::class => [
            RegisterSessionOnLogin::class,
        ],
        Logout::class => [
            DeregisterSessionOnLogout::class,
        ],
        SubscriptionPlanChanged::class => [
            DisableSsoOnEntitlementLoss::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
