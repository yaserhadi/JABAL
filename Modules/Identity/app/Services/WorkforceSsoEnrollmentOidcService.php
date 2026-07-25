<?php

namespace Modules\Identity\Services;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-099: start enrollment OIDC using invitation-bound IdP version (reuses Facile stack).
 */
final class WorkforceSsoEnrollmentOidcService
{
    public function __construct(
        protected AuthenticationTransactionService $transactions,
        protected SsoConfigService $configService,
        protected WorkforceSsoEnrollmentInvitationService $invitations,
        protected SsoOperationalGate $operationalGate,
        protected TenantAddressingProfile $addressing,
    ) {}

    public function startEnrollmentOidc(
        Tenant $tenant,
        WorkforceSsoEnrollmentInvitation $invitation,
        TenantUser $authenticatedUser,
        Request $request,
    ): RedirectResponse {
        if (! $this->addressing->isHost()) {
            abort(404);
        }

        $this->invitations->assertActorMatchesInvitation($authenticatedUser, $invitation);

        if (! $invitation->isPending()) {
            throw new SsoSecurityException('Invitation is not pending.');
        }

        $destinationHost = strtolower($request->getHost());
        if ($destinationHost !== strtolower($invitation->tenant_host)) {
            throw new SsoSecurityException('Invitation host mismatch.');
        }

        $this->operationalGate->assertMayProceed(
            $tenant,
            SsoOperationalGate::STAGE_INITIATION,
            (string) $invitation->sso_config_version_id,
            (string) $authenticatedUser->id,
        );

        $version = $this->configService->findVersionForTenant($tenant, (string) $invitation->sso_config_version_id);
        if ($version === null || ! $version->mayServeNewProductionLogin()) {
            throw new SsoSecurityException('Invitation IdP version is not active.');
        }

        $created = $this->transactions->create([
            'tenant_id' => (string) $tenant->id,
            'domain_id' => null,
            'destination_host' => $destinationHost,
            'addressing_profile' => 'host',
            'post_login_path' => '/auth/enterprise-sso/enrollment/complete',
            'idp_configuration_version_id' => (string) $invitation->sso_config_version_id,
            'expected_issuer' => is_string($version->issuer_url) ? $version->issuer_url : null,
            'purpose' => SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT,
            'enrollment_invitation_id' => (string) $invitation->id,
            'intended_user_id' => (string) $invitation->intended_user_id,
        ]);

        $initiateUrl = $this->authHostInitiateUrl($created['initiation_reference']);
        $secure = $request->isSecure();
        $ttl = $this->transactions->transactionTtlSeconds();

        $response = redirect()->away($initiateUrl);
        $response->headers->setCookie(
            SsoBrowserBindingCookieFactory::make(
                SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                $created['tenant_continuation_secret'],
                $ttl,
                $secure,
            )
        );

        return $response;
    }

    protected function authHostInitiateUrl(string $initiationReference): string
    {
        $authHost = $this->addressing->authHost();
        if ($authHost === '') {
            throw new SsoSecurityException('Auth Host is not configured.');
        }

        $scheme = $this->addressing->canonicalScheme() ?: 'https';

        return $scheme.'://'.$authHost.'/auth/enterprise-sso/initiate?t='.rawurlencode($initiationReference);
    }
}
