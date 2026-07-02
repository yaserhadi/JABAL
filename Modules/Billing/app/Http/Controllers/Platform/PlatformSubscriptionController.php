<?php

namespace Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Billing\Http\Requests\ChangeTenantPlanRequest;
use Modules\Billing\Http\Requests\UpdateSubscriptionSeatLimitRequest;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Models\Tenant;

class PlatformSubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
    ) {}

    public function show(Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->findForTenant($tenant->id);

        if (! $subscription) {
            return ApiResponse::error(
                'subscription_not_found',
                'No subscription found for tenant.',
                ['tenant_id' => $tenant->id],
                404
            );
        }

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    public function changePlan(ChangeTenantPlanRequest $request, Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->changePlan(
            $tenant->id,
            $request->validated('plan_code'),
        );

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    public function updateSeatLimit(UpdateSubscriptionSeatLimitRequest $request, Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->updateSeatLimit(
            $tenant->id,
            $request->validated('seat_limit'),
        );

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    public function suspend(Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->suspend($tenant->id);

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    public function reactivate(Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->reactivate($tenant->id);

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    public function cancel(Tenant $tenant): JsonResponse
    {
        $subscription = $this->subscriptions->cancel($tenant->id);

        return ApiResponse::success($this->subscriptionPayload($tenant->id, $subscription));
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionPayload(string $tenantId, Subscription $subscription): array
    {
        $subscription->loadMissing('plan');

        return [
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'plan_code' => $subscription->plan?->code,
            'plan_name' => $subscription->plan?->name,
            'status' => $subscription->status,
            'seat_limit' => $subscription->seat_limit,
            'plan_seat_limit' => $subscription->plan?->seat_limit,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }
}
