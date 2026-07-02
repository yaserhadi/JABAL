<?php

namespace Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Billing\Models\Plan;

class PlatformPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->with(['entitlements' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('code')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'seat_limit' => $plan->seat_limit,
                'entitlements' => $plan->entitlements->map(fn ($e) => [
                    'code' => $e->code,
                    'name' => $e->name,
                ])->values()->all(),
            ]);

        return ApiResponse::success(['plans' => $plans]);
    }
}
