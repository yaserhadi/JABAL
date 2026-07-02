<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Models\Tenant;

class BootstrapSubscriptionsCommand extends Command
{
    protected $signature = 'billing:bootstrap-subscriptions
                            {--plan=standard : Default plan code for new subscriptions}';

    protected $description = 'Ensure every tenant has an active subscription (idempotent)';

    public function handle(SubscriptionService $subscriptions): int
    {
        $planCode = (string) $this->option('plan');
        $created = 0;
        $skipped = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($subscriptions, $planCode, &$created, &$skipped) {
            $hadActive = $subscriptions->findActiveForTenant($tenant->id) !== null;

            if ($hadActive) {
                $skipped++;

                return;
            }

            $subscriptions->ensureDefaultSubscription($tenant->id, $planCode);
            $created++;
        });

        $this->info("Bootstrap complete: {$created} subscription(s) created, {$skipped} tenant(s) already had active subscription.");

        return self::SUCCESS;
    }
}
