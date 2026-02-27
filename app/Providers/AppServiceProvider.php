<?php

namespace App\Providers;

use App\Support\Context\ActorContext;
use App\Support\Context\ExecutionContext;
use App\Support\Context\RequestContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register RequestContext as singleton
        $this->app->singleton(RequestContext::class, function () {
            return RequestContext::getInstance();
        });

        // Register ActorContext as singleton
        $this->app->singleton(ActorContext::class, function () {
            return ActorContext::getInstance();
        });

        // Register ExecutionContext as singleton
        $this->app->singleton(ExecutionContext::class, function () {
            return ExecutionContext::getInstance();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
