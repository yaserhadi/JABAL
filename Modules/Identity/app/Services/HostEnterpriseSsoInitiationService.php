<?php

namespace Modules\Identity\Services;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS3: Tenant Host start + Auth Host initiate (no callback / Handoff / session).
 */
class HostEnterpriseSsoInitiationService
{
    public function __construct(
        protected AuthenticationTransactionService $transactions,
        protected SsoConfigService $configService,
        protected SsoAuthService $ssoAuthService,
        protected SsoOperationalGate $operationalGate,
        protected TenantAddressingProfile $addressing,
    ) {}

    public function startOnTenantHost(Tenant $tenant, Request $request): RedirectResponse
    {
        if (! $this->addressing->isHost()) {
            abort(404);
        }

        $actorUserId = $request->user()?->getAuthIdentifier();
        $this->operationalGate->assertMayProceed(
            $tenant,
            SsoOperationalGate::STAGE_INITIATION,
            null,
            is_string($actorUserId) ? $actorUserId : null,
        );

        $versionId = $this->configService->getActiveVersionId($tenant);
        if ($versionId === null) {
            throw new SsoSecurityException('Active IdP configuration version is required.');
        }

        // Re-read version under race: must still be active at bind time.
        $version = $this->configService->findVersionForTenant($tenant, $versionId);
        if ($version === null || ! $version->mayServeNewProductionLogin()) {
            throw new SsoSecurityException('IdP configuration version is not active for production login.');
        }

        $destinationHost = strtolower($request->getHost());
        $expectedIssuer = $this->configService->getConfiguredIssuer($tenant);

        $created = $this->transactions->create([
            'tenant_id' => (string) $tenant->id,
            'domain_id' => null,
            'destination_host' => $destinationHost,
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'expected_issuer' => $expectedIssuer,
            'purpose' => SsoAuthenticationTransaction::PURPOSE_ORDINARY,
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

    public function initiateOnAuthHost(Request $request): RedirectResponse
    {
        if (! $this->addressing->isHost()) {
            abort(404);
        }

        if (strtolower($request->getHost()) !== strtolower($this->addressing->authHost())) {
            abort(404);
        }

        // Opaque initiation reference only — ignore browser OIDC / Tenant control params.
        $reference = trim((string) $request->query('t', ''));
        if ($reference === '') {
            abort(404);
        }

        $transaction = $this->transactions->findByInitiationReference($reference);
        if (! $transaction) {
            abort(404);
        }

        if ($transaction->isExpired()) {
            abort(404);
        }

        if ($transaction->status !== SsoAuthenticationTransaction::STATUS_PENDING) {
            abort(404);
        }

        if ($transaction->addressing_profile !== 'host') {
            abort(404);
        }

        $tenant = Tenant::query()->find($transaction->tenant_id);
        if (! $tenant instanceof Tenant || $tenant->status !== 'active') {
            abort(404);
        }

        $this->operationalGate->assertMayProceed(
            $tenant,
            SsoOperationalGate::STAGE_AUTH_ADVANCE,
            (string) $transaction->idp_configuration_version_id,
        );

        $materials = $this->transactions->authorizationMaterials($transaction);
        if ($materials === null) {
            abort(404);
        }

        $authBindingSecret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $this->transactions->attachAuthBinding($transaction, $authBindingSecret);

        $authorizeUrl = $this->ssoAuthService->buildHostAuthorizationRedirectUrl($tenant, $materials);
        $secure = $request->isSecure();
        $ttl = $this->transactions->transactionTtlSeconds();

        $response = redirect()->away($authorizeUrl);
        $response->headers->setCookie(
            SsoBrowserBindingCookieFactory::make(
                SsoBrowserBindingCookieFactory::AUTH_BINDING,
                $authBindingSecret,
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
