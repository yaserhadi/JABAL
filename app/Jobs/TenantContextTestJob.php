<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Test job for verifying tenant context in queued jobs.
 *
 * PHASE 2: Used by TenancyBootstrapTest to verify QueueTenancyBootstrapper.
 * Stores the current tenant ID in both cache and static property for testing.
 */
class TenantContextTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?string $lastTenantId = null;

    public function __construct(
        public string $cacheKey
    ) {}

    public function handle(): void
    {
        // Store in static property for test verification
        static::$lastTenantId = tenancy()->tenant?->id;

        // Also store in cache for alternative verification
        Cache::put($this->cacheKey, tenancy()->tenant?->id, 60);
    }
}
