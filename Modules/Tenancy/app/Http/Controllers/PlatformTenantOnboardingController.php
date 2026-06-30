<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Http\Requests\OnboardOrganizationTenantRequest;
use Modules\Tenancy\Services\TenantOnboardingService;

class PlatformTenantOnboardingController extends Controller
{
    public function store(
        OnboardOrganizationTenantRequest $request,
        TenantOnboardingService $onboarding,
    ): JsonResponse {
        $result = $onboarding->onboardOrganizationTenant(
            TenantOnboardingInput::fromArray($request->validated())
        );

        return response()->json(
            $result->toArray(),
            $onboarding->isProvisioningComplete($result) ? 201 : 202
        );
    }
}
