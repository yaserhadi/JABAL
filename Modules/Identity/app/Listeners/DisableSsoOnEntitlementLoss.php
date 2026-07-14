<?php

namespace Modules\Identity\Listeners;

use Modules\Billing\Events\SubscriptionPlanChanged;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008: Disable tenant SSO when plan loses sso_available; preserve config/secrets; no session revoke.
 */
class DisableSsoOnEntitlementLoss
{
    public function __construct(
        protected SsoConfigService $ssoConfigService,
        protected SecurityFeatureGate $featureGate,
    ) {}

    public function handle(SubscriptionPlanChanged $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if (! $tenant) {
            return;
        }

        if ($this->featureGate->isSsoAvailable($tenant)) {
            $this->ssoConfigService->clearEntitlementDisableFlag($tenant);

            return;
        }

        $this->ssoConfigService->disableForEntitlementLoss($tenant);
    }
}
