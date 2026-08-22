<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Services\SsoOperationalExposureService;
use Modules\Identity\Services\SsoReadinessAccountingService;
use Modules\Identity\Support\Auth\SsoUserReadinessState;

/**
 * WAVE-5: Mandatory SSO Enrollment user surface (no Skip / Maybe Later).
 */
class MandatorySsoEnrollmentController extends Controller
{
    public function show(Request $request): Response
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        $user = $request->user();
        abort_unless($user, 403);

        $classified = app(SsoReadinessAccountingService::class)->classifyUser($tenant, $user);
        if (in_array($classified['state'], [
            SsoUserReadinessState::READY,
            SsoUserReadinessState::EXCEPTION,
        ], true)) {
            return redirect()->to(
                app(\App\Http\Auth\TenantEntryUrlResolver::class)->dashboardUrl($tenant)
            );
        }

        $exposure = app(SsoOperationalExposureService::class);
        $ssoOperational = $exposure->isExposedOnTenantLogin($tenant, (string) $user->id);
        $ssoStartUrl = $ssoOperational ? $exposure->startUrlForTenantLogin($tenant) : null;

        return Inertia::render('Security/SsoEnforcement/MandatoryEnrollment', [
            'readinessState' => $classified['state'],
            'reason' => $classified['reason'],
            'ssoOperational' => $ssoOperational,
            'ssoStartUrl' => $ssoStartUrl,
            'skipAllowed' => false,
            'maybeLaterAllowed' => false,
        ]);
    }
}
