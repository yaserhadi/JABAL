<?php

namespace Modules\Identity\Services;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Support\Sso\SsoAuthorizationResponseParser;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Identity\Support\Sso\TransactionAuthSessionAdapter;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * BK-082 WS4: Auth Host callback + token exchange + Handoff mint (no Tenant session).
 */
class HostEnterpriseSsoCallbackService
{
    public function __construct(
        protected AuthenticationTransactionService $transactions,
        protected SsoConfigService $configService,
        protected SsoAuthService $ssoAuthService,
        protected SsoAuthorizationResponseParser $responseParser,
        protected SsoIdentityResolver $identityResolver,
        protected SsoOperationalGate $operationalGate,
        protected TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request): RedirectResponse
    {
        if (! $this->addressing->isHost()) {
            abort(404);
        }

        if (strtolower($request->getHost()) !== strtolower($this->addressing->authHost())) {
            abort(404);
        }

        $responseMode = (string) config('identity.sso.host_response_mode', SsoAuthorizationResponseParser::MODE_QUERY);

        try {
            $parsed = $this->responseParser->parse($request, $responseMode);
        } catch (SsoSecurityException) {
            abort(404);
        }

        $transaction = $this->transactions->findByState($parsed['state']);
        if (! $transaction) {
            abort(404);
        }

        $bindingCookie = (string) $request->cookie(SsoBrowserBindingCookieFactory::AUTH_BINDING, '');
        if ($bindingCookie === '' || ! $this->transactions->authBindingMatches($transaction, $bindingCookie)) {
            abort(404);
        }

        if ($transaction->isExpired()) {
            $this->transactions->failTerminal($transaction, 'expired');
            abort(404);
        }

        if ($transaction->status !== SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK) {
            abort(404);
        }

        if ($transaction->addressing_profile !== 'host') {
            abort(404);
        }

        if ($parsed['error'] !== null) {
            $this->transactions->failTerminal($transaction, 'idp_error');

            return $this->redirectToTenantLogin($transaction)->withCookie(
                SsoBrowserBindingCookieFactory::clear(SsoBrowserBindingCookieFactory::AUTH_BINDING, $request->isSecure())
            );
        }

        $reserved = $this->transactions->reserveCallback($transaction->id);
        if (! $reserved) {
            abort(404);
        }

        $tenant = Tenant::query()->find($reserved->tenant_id);
        if (! $tenant instanceof Tenant || $tenant->status !== 'active') {
            $this->transactions->failTerminal($reserved, 'tenant_inactive');
            abort(404);
        }

        $version = $this->configService->findVersionForTenant($tenant, (string) $reserved->idp_configuration_version_id);
        if ($version === null) {
            $this->transactions->failTerminal($reserved, 'idp_version_missing');
            abort(404);
        }

        $nonce = $this->transactions->decryptNonce($reserved);
        $pkceVerifier = $this->transactions->decryptPkceVerifier($reserved);
        if ($nonce === null || $pkceVerifier === null) {
            $this->transactions->failTerminal($reserved, 'secrets_unavailable');
            abort(404);
        }

        $authSession = TransactionAuthSessionAdapter::fromTransactionMaterials(
            $parsed['state'],
            $nonce,
            $pkceVerifier,
        );

        $redirectUri = $this->ssoAuthService->callbackRedirectUri();
        $code = (string) $parsed['code'];

        try {
            $tokenSet = $this->ssoAuthService->exchangeHostAuthorizationCode(
                $tenant,
                $version,
                $redirectUri,
                [
                    'code' => $code,
                    'state' => $parsed['state'],
                ],
                $authSession,
            );
            unset($code);

            $claims = $this->ssoAuthService->extractValidatedClaims($tokenSet);
            $idClaims = $tokenSet->claims();
        } catch (Throwable $e) {
            unset($code);
            $reason = $this->isAmbiguousTimeout($e) ? 'token_exchange_ambiguous' : 'token_exchange_failed';
            $this->transactions->failTerminal($reserved, $reason);
            abort(404);
        }

        // D9 Auth Host current-state revalidation after successful token validation.
        try {
            $this->ssoAuthService->assertTenantMayStartSso($tenant);
        } catch (SsoSecurityException) {
            $this->transactions->failTerminal($reserved, 'revalidation_failed');
            abort(404);
        }

        $reloadedVersion = $this->configService->findVersionForTenant($tenant, (string) $reserved->idp_configuration_version_id);
        if ($reloadedVersion === null || (string) $reloadedVersion->id !== (string) $version->id) {
            $this->transactions->failTerminal($reserved, 'version_revalidation_failed');
            abort(404);
        }

        $expectedIssuer = is_string($reserved->expected_issuer) && $reserved->expected_issuer !== ''
            ? $reserved->expected_issuer
            : (string) $version->issuer_url;

        if ($reserved->purpose === SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT) {
            return $this->handleEnrollmentCallback($request, $reserved, $claims, $expectedIssuer);
        }

        tenancy()->initialize($tenant);
        try {
            $resolution = $this->identityResolver->resolveExistingLinkOnly($tenant, $claims, $expectedIssuer);
        } finally {
            tenancy()->end();
        }

        if (! $resolution->succeeded() || $resolution->user === null || $resolution->identityLink === null) {
            $this->transactions->failTerminal(
                $reserved,
                $resolution->failureReason ?? SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED
            );

            return $this->redirectToTenantLogin($reserved)->withCookie(
                SsoBrowserBindingCookieFactory::clear(SsoBrowserBindingCookieFactory::AUTH_BINDING, $request->isSecure())
            );
        }

        try {
            $this->operationalGate->assertMayProceed(
                $tenant,
                SsoOperationalGate::STAGE_HANDOFF_ISSUE,
                (string) $reserved->idp_configuration_version_id,
            );
            $issued = $this->transactions->issueHandoff($reserved, [
                'user_id' => (string) $resolution->user->id,
                'identity_link_id' => (string) $resolution->identityLink->id,
                'assurance_evidence' => $this->boundedAssuranceEvidence(is_array($idClaims) ? $idClaims : []),
            ]);
        } catch (SsoSecurityException $e) {
            $this->transactions->failTerminal($reserved, 'handoff_blocked_'.$e->getMessage());
            abort(404);
        } catch (Throwable) {
            $this->transactions->failTerminal($reserved, 'handoff_issue_failed');
            abort(404);
        }

        $handoffUrl = $this->tenantHandoffUrl($reserved, $issued['reference']);

        return redirect()->away($handoffUrl)->withCookie(
            SsoBrowserBindingCookieFactory::clear(SsoBrowserBindingCookieFactory::AUTH_BINDING, $request->isSecure())
        );
    }

    /**
     * BK-099: enrollment purpose — Facile-validated issuer+subject only; no link, no handoff, no session.
     */
    protected function handleEnrollmentCallback(
        Request $request,
        SsoAuthenticationTransaction $reserved,
        SsoValidatedClaims $claims,
        string $expectedIssuer,
    ): RedirectResponse {
        $normalizedExpected = rtrim(trim($expectedIssuer), '/');
        $normalizedClaimsIssuer = rtrim(trim($claims->issuer), '/');

        if ($normalizedExpected === '' || $normalizedExpected !== $normalizedClaimsIssuer) {
            $this->transactions->failTerminal($reserved, 'issuer_mismatch');
            abort(404);
        }

        if ($claims->subject === '') {
            $this->transactions->failTerminal($reserved, 'subject_missing');
            abort(404);
        }

        $invitationId = $reserved->enrollment_invitation_id;
        $intendedUserId = $reserved->intended_user_id;
        if (! is_string($invitationId) || $invitationId === '' || ! is_string($intendedUserId) || $intendedUserId === '') {
            $this->transactions->failTerminal($reserved, 'enrollment_binding_missing');
            abort(404);
        }

        try {
            $this->operationalGate->assertMayProceed(
                Tenant::query()->findOrFail($reserved->tenant_id),
                SsoOperationalGate::STAGE_HANDOFF_ISSUE,
                (string) $reserved->idp_configuration_version_id,
            );
            $issued = $this->transactions->issueEnrollmentContinuation($reserved, [
                'issuer' => $normalizedClaimsIssuer,
                'subject' => $claims->subject,
                'invitation_id' => $invitationId,
                'intended_user_id' => $intendedUserId,
            ]);
        } catch (SsoSecurityException $e) {
            $this->transactions->failTerminal($reserved, 'enrollment_blocked_'.$e->getMessage());
            abort(404);
        } catch (Throwable) {
            $this->transactions->failTerminal($reserved, 'enrollment_continuation_failed');
            abort(404);
        }

        $completeUrl = $this->tenantEnrollmentCompleteUrl($reserved, $issued['reference']);

        return redirect()->away($completeUrl)->withCookie(
            SsoBrowserBindingCookieFactory::clear(SsoBrowserBindingCookieFactory::AUTH_BINDING, $request->isSecure())
        );
    }

    protected function redirectToTenantLogin(SsoAuthenticationTransaction $transaction): RedirectResponse
    {
        $scheme = $this->addressing->canonicalScheme() ?: 'https';

        return redirect()->away($scheme.'://'.$transaction->destination_host.'/login');
    }

    protected function tenantHandoffUrl(SsoAuthenticationTransaction $transaction, string $handoffReference): string
    {
        $scheme = $this->addressing->canonicalScheme() ?: 'https';

        return $scheme.'://'.$transaction->destination_host
            .'/auth/enterprise-sso/handoff?h='.rawurlencode($handoffReference);
    }

    protected function tenantEnrollmentCompleteUrl(SsoAuthenticationTransaction $transaction, string $continuationReference): string
    {
        $scheme = $this->addressing->canonicalScheme() ?: 'https';

        return $scheme.'://'.$transaction->destination_host
            .'/auth/enterprise-sso/enrollment/complete?c='.rawurlencode($continuationReference);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    protected function boundedAssuranceEvidence(array $claims): array
    {
        $out = [];

        if (isset($claims['acr']) && is_string($claims['acr']) && strlen($claims['acr']) <= 128) {
            $out['acr'] = $claims['acr'];
        }

        if (isset($claims['amr']) && is_array($claims['amr'])) {
            $amr = [];
            foreach ($claims['amr'] as $value) {
                if (is_string($value) && $value !== '' && strlen($value) <= 64) {
                    $amr[] = $value;
                }
                if (count($amr) >= 8) {
                    break;
                }
            }
            if ($amr !== []) {
                $out['amr'] = $amr;
            }
        }

        if (isset($claims['auth_time']) && is_numeric($claims['auth_time'])) {
            $out['auth_time'] = (int) $claims['auth_time'];
        }

        if (isset($claims['sid']) && is_string($claims['sid']) && $claims['sid'] !== '' && strlen($claims['sid']) <= 255) {
            $out['sid'] = $claims['sid'];
        }

        return $out;
    }

    protected function isAmbiguousTimeout(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'operation timed out');
    }
}
