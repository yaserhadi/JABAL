<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Controllers\Concerns\RequiresMfaStepUp;
use Modules\Identity\Services\SsoConfigGovernanceService;
use Modules\Identity\Services\SsoKillSwitchService;

/**
 * BK-082 WS8: minimal IdP governance / kill-switch API (not Host user-facing SSO UI).
 */
class SsoGovernanceController extends Controller
{
    use RequiresMfaStepUp;

    public function __construct(
        protected SsoConfigGovernanceService $governance,
        protected SsoKillSwitchService $killSwitches,
    ) {
        $this->middleware('permission:tenant.sso.validate')->only(['validateVersion']);
        $this->middleware('permission:tenant.sso.test')->only(['markTestOnly']);
        $this->middleware('permission:tenant.sso.approve')->only(['approveVersion']);
        $this->middleware('permission:tenant.sso.activate')->only(['activateVersion', 'recover']);
        $this->middleware('permission:tenant.sso.disable')->only(['setRollout', 'disableVersion']);
        $this->middleware('permission:tenant.sso.kill-switch')->only([
            'pauseTenant',
            'securityDisable',
            'pausePlatform',
            'disablePlatform',
        ]);
        $this->middleware('permission:tenant.sso.rotate-secret')->only(['revokeSecret']);
    }

    public function validateVersion(Request $request, string $versionId): JsonResponse
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->governance->validateVersion($tenant, $versionId);

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function markTestOnly(Request $request, string $versionId): JsonResponse
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->governance->markTestOnly($tenant, $versionId);

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function approveVersion(Request $request, string $versionId): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.approve');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->governance->approveVersion($tenant, $versionId);

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function activateVersion(Request $request, string $versionId): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.activate');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->governance->activateVersion($tenant, $versionId);

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function setRollout(Request $request): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.rollout');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $state = (string) $request->input('rollout_state', '');
        $config = $this->governance->setRolloutState($tenant, $state);

        return ApiResponse::success(['rollout_state' => $config->rollout_state]);
    }

    public function disableVersion(Request $request, string $versionId): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.disable');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->killSwitches->disableVersion($tenant, $versionId, 'admin_disable');

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function pauseTenant(Request $request): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.kill_switch');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $config = $this->killSwitches->pauseTenant($tenant);

        return ApiResponse::success(['rollout_state' => $config->rollout_state]);
    }

    public function securityDisable(Request $request): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.kill_switch');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $reason = (string) $request->input('reason', 'security_disable');
        $config = $this->killSwitches->securityDisableTenant($tenant, $reason, true);

        return ApiResponse::success(['rollout_state' => $config->rollout_state]);
    }

    public function recover(Request $request, string $versionId): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.activate');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->governance->recoverFromSecurityDisable($tenant, $versionId);

        return ApiResponse::success(['id' => $version->id, 'status' => $version->status]);
    }

    public function revokeSecret(Request $request, string $versionId): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.rotate_secret');
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $version = $this->killSwitches->revokeVersionSecret($tenant, $versionId);

        return ApiResponse::success([
            'id' => $version->id,
            'secret_revoked' => $version->secret_revoked_at !== null,
        ]);
    }

    public function pausePlatform(Request $request): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.kill_switch');
        $paused = $request->boolean('paused', true);
        $control = $this->killSwitches->pausePlatformInitiations($paused);

        return ApiResponse::success(['pause_new_initiations' => $control->pause_new_initiations]);
    }

    public function disablePlatform(Request $request): JsonResponse
    {
        $this->requireMfaStepUp($request, 'sso.kill_switch');
        $disabled = $request->boolean('disabled', true);
        $control = $this->killSwitches->disablePlatformEnterpriseSso($disabled);

        return ApiResponse::success(['disable_enterprise_sso' => $control->disable_enterprise_sso]);
    }
}
