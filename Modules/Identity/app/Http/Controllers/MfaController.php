<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\HostEnterpriseSsoHandoffService;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\Tenant;

/**
 * MFA enroll/challenge — Tenant context from initialized tenancy only (BK-108).
 *
 * Host and Path both register these actions, but Path {tenant} is consumed by
 * tenancy initialization middleware. Action signatures intentionally omit any
 * $tenant argument so Host leftover {tenant_label} cannot inject as a UUID/slug lookup.
 */
class MfaController extends Controller
{
    public function __construct(
        protected MfaService $mfaService
    ) {}

    public function showEnroll(Request $request): InertiaResponse|JsonResponse
    {
        $tenantModel = $this->resolveInitializedTenant();

        if (! $this->mfaService->isMfaAvailable($tenantModel)) {
            abort(403, 'MFA is not available for this tenant.');
        }

        $user = $request->user();
        $setup = $this->mfaService->beginEnrollment($user);

        if ($request->expectsJson()) {
            return ApiResponse::success([
                'qr_url' => $setup['qr_url'],
                'secret' => $setup['secret'],
            ]);
        }

        return Inertia::render('Security/MfaEnroll', [
            'tenant' => TenantInertiaProps::from($tenantModel),
            'qr_url' => $setup['qr_url'],
            'secret' => $setup['secret'],
        ]);
    }

    public function confirmEnroll(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['code' => 'required|string']);
        $codes = $this->mfaService->confirmEnrollment($request->user(), $request->string('code')->toString());

        if ($request->expectsJson()) {
            return ApiResponse::success(['recovery_codes' => $codes]);
        }

        $tenantModel = $this->resolveInitializedTenant();
        $user = $request->user();
        if ($user instanceof TenantUser) {
            $completion = app(HostEnterpriseSsoHandoffService::class)
                ->completeMfaContinuation($request, $tenantModel, $user);
            if ($completion instanceof RedirectResponse) {
                return $completion;
            }
        }

        return redirect()->to(
            app(\App\Http\Auth\TenantEntryUrlResolver::class)->dashboardUrl($tenantModel)
        );
    }

    public function showChallenge(Request $request): InertiaResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return ApiResponse::success(['challenge' => true]);
        }

        $tenantModel = $this->resolveInitializedTenant();

        return Inertia::render('Security/MfaChallenge', [
            'tenant' => TenantInertiaProps::from($tenantModel),
        ]);
    }

    public function verifyChallenge(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        if (! $this->mfaService->verifyChallenge($request->user(), $request->string('code')->toString())) {
            abort(422, 'Invalid MFA code.');
        }

        if ($request->expectsJson()) {
            return ApiResponse::success(['verified' => true]);
        }

        $tenantModel = $this->resolveInitializedTenant();
        $user = $request->user();
        if ($user instanceof TenantUser) {
            $completion = app(HostEnterpriseSsoHandoffService::class)
                ->completeMfaContinuation($request, $tenantModel, $user);
            if ($completion instanceof RedirectResponse) {
                return $completion;
            }
        }

        return app(\App\Http\Auth\TenantEntryUrlResolver::class)->redirectAfterLogin($request, $tenantModel);
    }

    protected function resolveInitializedTenant(): Tenant
    {
        $current = tenancy()->tenant;
        if ($current instanceof Tenant) {
            return $current;
        }

        abort(404);
    }
}
