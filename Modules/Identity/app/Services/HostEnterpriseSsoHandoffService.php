<?php

namespace Modules\Identity\Services;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Models\User;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Sso\SsoAssuranceEvaluator;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoMfaContinuation;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * BK-082 WS5: Tenant Host Handoff consume + MFA decision + UserSession + session issuance.
 */
class HostEnterpriseSsoHandoffService
{
    public function __construct(
        protected AuthenticationTransactionService $transactions,
        protected SsoConfigService $configService,
        protected MfaService $mfaService,
        protected SsoAssuranceEvaluator $assuranceEvaluator,
        protected SessionRegistryService $sessionRegistry,
        protected SsoOperationalGate $operationalGate,
        protected TenantAddressingProfile $addressing,
        protected TenantEntryUrlResolver $entryUrls,
        protected SsoSecurityAudit $securityAudit,
    ) {}

    public function handle(Request $request): RedirectResponse
    {
        if (! $this->addressing->isHost()) {
            abort(404);
        }

        $tenant = tenancy()->tenant instanceof Tenant ? tenancy()->tenant : null;
        if (! $tenant instanceof Tenant || $tenant->status !== 'active') {
            abort(404);
        }

        $destinationHost = strtolower($request->getHost());
        $reference = trim((string) $request->query('h', ''));
        $continuation = (string) $request->cookie(SsoBrowserBindingCookieFactory::TENANT_CONTINUATION, '');

        if ($reference === '' || $continuation === '') {
            abort(404);
        }

        $peek = $this->transactions->peekHandoff($reference);
        if (! $peek) {
            abort(404);
        }

        if ($peek->audience !== SsoTenantHandoff::AUDIENCE_TENANT_HOST) {
            abort(404);
        }

        if ($peek->tenant_id !== (string) $tenant->id || strtolower($peek->destination_host) !== $destinationHost) {
            abort(404);
        }

        $txn = SsoAuthenticationTransaction::query()->whereKey($peek->transaction_id)->first();
        $boundVersionId = $txn ? (string) $txn->idp_configuration_version_id : null;
        try {
            $this->operationalGate->assertMayProceed(
                $tenant,
                SsoOperationalGate::STAGE_SESSION_CREATE,
                $boundVersionId,
                (string) $peek->user_id,
            );
        } catch (\Modules\Identity\Exceptions\SsoSecurityException) {
            abort(404);
        }

        $purpose = $this->transactionPurpose($peek);

        if (Auth::guard('web')->check()) {
            $current = Auth::guard('web')->user();
            if (! $current instanceof TenantUser) {
                abort(404);
            }

            if ((string) $current->tenant_id !== (string) $tenant->id) {
                abort(404);
            }

            if ((string) $current->id !== (string) $peek->user_id) {
                abort(404);
            }

            if ($purpose === SsoAuthenticationTransaction::PURPOSE_ORDINARY) {
                $consumed = $this->transactions->consumeHandoff(
                    $reference,
                    (string) $tenant->id,
                    $destinationHost,
                    $continuation,
                );
                if (! $consumed) {
                    abort(404);
                }

                return $this->redirectClean($tenant, $consumed->post_login_path, $request)
                    ->withCookie(SsoBrowserBindingCookieFactory::clear(
                        SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                        $request->isSecure(),
                    ));
            }
        }

        $consumed = $this->transactions->consumeHandoff(
            $reference,
            (string) $tenant->id,
            $destinationHost,
            $continuation,
        );
        if (! $consumed) {
            abort(404);
        }

        $user = $this->loadAndRevalidateUser($tenant, $consumed);
        if ($user === null) {
            return $this->terminalFailureRedirect($tenant, $request);
        }

        $fullSessionAllowed = $this->assuranceEvaluator->isSufficientForFullSession(
            $tenant,
            $user,
            is_array($consumed->assurance_evidence) ? $consumed->assurance_evidence : null,
        );

        if (! $fullSessionAllowed) {
            return $this->beginMfaContinuation($request, $tenant, $user, $consumed);
        }

        return $this->issueFullSession($request, $tenant, $user, $consumed, $purpose);
    }

    /**
     * Complete deferred UserSession after MFA challenge/enroll (Host SSO continuation).
     */
    public function completeMfaContinuation(Request $request, Tenant $tenant, TenantUser $user): ?RedirectResponse
    {
        $payload = SsoMfaContinuation::pullValid($request->session(), (string) $tenant->id, (string) $user->id);
        if ($payload === null) {
            return null;
        }

        SsoMfaContinuation::clear($request->session());

        $registryUser = $user instanceof User ? $user : User::query()->whereKey($user->id)->first();
        if (! $registryUser instanceof User) {
            return null;
        }

        $handoff = SsoTenantHandoff::query()->whereKey($payload['handoff_id'] ?? '')->first();
        $federation = $handoff instanceof SsoTenantHandoff
            ? $this->federationAttributesFromHandoff($handoff)
            : [];

        try {
            $this->sessionRegistry->register($registryUser, $request, $request->session()->getId(), $federation);
            $this->securityAudit->record('sso.session.registered', [
                'tenant_id' => (string) $tenant->id,
                'correlation_id' => $federation['correlation_id'] ?? null,
                'identity_link_id' => $federation['identity_link_id'] ?? null,
                'reason' => 'mfa_continuation_complete',
            ]);
        } catch (Throwable) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to($this->entryUrls->loginUrl($tenant));
        }

        return $this->redirectClean($tenant, $payload['post_login_path'], $request);
    }

    protected function beginMfaContinuation(
        Request $request,
        Tenant $tenant,
        User $user,
        SsoTenantHandoff $handoff,
    ): RedirectResponse {
        SsoMfaContinuation::store($request->session(), [
            'user_id' => (string) $user->id,
            'tenant_id' => (string) $tenant->id,
            'post_login_path' => (string) $handoff->post_login_path,
            'handoff_id' => (string) $handoff->id,
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);
        // Re-store continuation after regenerate (session id changed; put again).
        SsoMfaContinuation::store($request->session(), [
            'user_id' => (string) $user->id,
            'tenant_id' => (string) $tenant->id,
            'post_login_path' => (string) $handoff->post_login_path,
            'handoff_id' => (string) $handoff->id,
        ]);

        $target = $this->mfaService->userHasConfirmedMfa($user)
            ? $this->entryUrls->namedRouteUrl('identity.mfa.challenge', $tenant)
            : $this->entryUrls->namedRouteUrl('identity.mfa.enroll', $tenant);

        return redirect()->to($target)->withCookie(SsoBrowserBindingCookieFactory::clear(
            SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
            $request->isSecure(),
        ));
    }

    protected function issueFullSession(
        Request $request,
        Tenant $tenant,
        User $user,
        SsoTenantHandoff $handoff,
        string $purpose,
    ): RedirectResponse {
        $alreadySameUser = Auth::guard('web')->check()
            && Auth::guard('web')->id() === $user->id
            && (string) (Auth::guard('web')->user()->tenant_id ?? '') === (string) $tenant->id;

        if ($alreadySameUser && $purpose === SsoAuthenticationTransaction::PURPOSE_ORDINARY) {
            return $this->redirectClean($tenant, $handoff->post_login_path, $request)
                ->withCookie(SsoBrowserBindingCookieFactory::clear(
                    SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                    $request->isSecure(),
                ));
        }

        $request->session()->put(SsoMfaContinuation::DEFER_USER_SESSION_KEY, true);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);
        $request->session()->forget(SsoMfaContinuation::DEFER_USER_SESSION_KEY);

        if (! $this->mfaService->isMfaRequired($tenant)) {
            $request->session()->put('mfa_verified_at', now()->toIso8601String());
        } elseif ($this->assuranceEvaluator->evidenceIndicatesMfa(
            is_array($handoff->assurance_evidence) ? $handoff->assurance_evidence : null
        )) {
            $request->session()->put('mfa_verified_at', now()->toIso8601String());
        }

        try {
            $this->sessionRegistry->register(
                $user,
                $request,
                $request->session()->getId(),
                $this->federationAttributesFromHandoff($handoff),
            );
            $this->securityAudit->record('sso.session.registered', [
                'tenant_id' => (string) $tenant->id,
                'correlation_id' => $handoff->correlation_id,
                'identity_link_id' => $handoff->identity_link_id,
                'handoff_id' => (string) $handoff->id,
                'reason' => 'full_session',
            ]);
        } catch (Throwable) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to($this->entryUrls->loginUrl($tenant))
                ->withCookie(SsoBrowserBindingCookieFactory::clear(
                    SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                    $request->isSecure(),
                ));
        }

        return $this->redirectClean($tenant, $handoff->post_login_path, $request)
            ->withCookie(SsoBrowserBindingCookieFactory::clear(
                SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                $request->isSecure(),
            ));
    }

    protected function loadAndRevalidateUser(Tenant $tenant, SsoTenantHandoff $handoff): ?User
    {
        if (! $this->configService->isOperationalForTenant($tenant)) {
            return null;
        }

        $userId = $handoff->user_id;
        $linkId = $handoff->identity_link_id;
        if (! is_string($userId) || $userId === '' || ! is_string($linkId) || $linkId === '') {
            return null;
        }

        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereKey($userId)
            ->first();

        if (! $user || $user->trashed()) {
            return null;
        }

        $link = TenantUserIdentity::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($linkId)
            ->where('user_id', $user->id)
            ->first();

        if (! $link) {
            return null;
        }

        $membership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            return null;
        }

        return $user;
    }

    protected function transactionPurpose(SsoTenantHandoff $handoff): string
    {
        $txn = SsoAuthenticationTransaction::query()->whereKey($handoff->transaction_id)->first();

        return is_string($txn?->purpose) && $txn->purpose !== ''
            ? $txn->purpose
            : SsoAuthenticationTransaction::PURPOSE_ORDINARY;
    }

    /**
     * @return array{idp_sid?: string, idp_issuer?: string, identity_link_id?: string, idp_configuration_version_id?: string, correlation_id?: string}
     */
    protected function federationAttributesFromHandoff(SsoTenantHandoff $handoff): array
    {
        $attrs = [
            'identity_link_id' => is_string($handoff->identity_link_id) ? $handoff->identity_link_id : null,
            'correlation_id' => is_string($handoff->correlation_id) ? $handoff->correlation_id : null,
        ];

        $evidence = is_array($handoff->assurance_evidence) ? $handoff->assurance_evidence : [];
        if (isset($evidence['sid']) && is_string($evidence['sid']) && $evidence['sid'] !== '') {
            $attrs['idp_sid'] = $evidence['sid'];
        }

        $txn = SsoAuthenticationTransaction::query()->whereKey($handoff->transaction_id)->first();
        if ($txn) {
            if (is_string($txn->expected_issuer) && $txn->expected_issuer !== '') {
                $attrs['idp_issuer'] = rtrim($txn->expected_issuer, '/');
            }
            if (is_string($txn->idp_configuration_version_id) && $txn->idp_configuration_version_id !== '') {
                $attrs['idp_configuration_version_id'] = $txn->idp_configuration_version_id;
            }
        }

        return array_filter($attrs, static fn ($v) => is_string($v) && $v !== '');
    }

    protected function redirectClean(Tenant $tenant, string $postLoginPath, Request $request): RedirectResponse
    {
        $path = $postLoginPath !== '' && str_starts_with($postLoginPath, '/')
            ? $postLoginPath
            : '/dashboard';

        if ($path === '/dashboard') {
            return redirect()->to($this->entryUrls->dashboardUrl($tenant));
        }

        return redirect()->to($this->entryUrls->entryUrl($tenant).$path);
    }

    protected function terminalFailureRedirect(Tenant $tenant, Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($this->entryUrls->loginUrl($tenant))
            ->withCookie(SsoBrowserBindingCookieFactory::clear(
                SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                $request->isSecure(),
            ));
    }
}
