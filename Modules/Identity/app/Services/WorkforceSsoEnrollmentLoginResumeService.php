<?php

namespace Modules\Identity\Services;

use Illuminate\Http\Request;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Models\WorkforceSsoEnrollmentLoginResume;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * BK-099: opaque server-authoritative login resume after invitation open (not bare invitation ID).
 */
final class WorkforceSsoEnrollmentLoginResumeService
{
    public function resumeTtlSeconds(): int
    {
        return max(60, (int) config('identity.sso.enrollment_login_resume_ttl', 600));
    }

    /**
     * @return array{plainToken: string, resumeCookie: Cookie, bindingCookie: Cookie}
     */
    public function issueResume(
        WorkforceSsoEnrollmentInvitation $invitation,
        string $tenantHost,
        Request $request,
    ): array {
        $plainToken = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $browserBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $ttl = $this->resumeTtlSeconds();
        $host = strtolower($tenantHost);

        WorkforceSsoEnrollmentLoginResume::query()->create([
            'invitation_id' => $invitation->id,
            'tenant_id' => $invitation->tenant_id,
            'tenant_host' => $host,
            'token_hash' => SsoSecretCrypto::proof($plainToken),
            'browser_binding_secret_hash' => SsoSecretCrypto::proof($browserBinding),
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $secure = $request->isSecure();

        return [
            'plainToken' => $plainToken,
            'resumeCookie' => SsoBrowserBindingCookieFactory::make(
                SsoBrowserBindingCookieFactory::ENROLLMENT_LOGIN_RESUME,
                $plainToken,
                $ttl,
                $secure,
            ),
            'bindingCookie' => SsoBrowserBindingCookieFactory::make(
                SsoBrowserBindingCookieFactory::ENROLLMENT_BROWSER_BINDING,
                $browserBinding,
                $ttl,
                $secure,
            ),
        ];
    }

    public function consumeAndValidate(
        string $plainToken,
        Request $request,
        string $tenantHost,
        Tenant $tenant,
    ): ?WorkforceSsoEnrollmentInvitation {
        if ($plainToken === '') {
            return null;
        }

        $binding = (string) $request->cookie(SsoBrowserBindingCookieFactory::ENROLLMENT_BROWSER_BINDING, '');
        if ($binding === '') {
            return null;
        }

        $resume = WorkforceSsoEnrollmentLoginResume::query()
            ->where('tenant_id', $tenant->id)
            ->where('token_hash', SsoSecretCrypto::proof($plainToken))
            ->first();

        if (! $resume instanceof WorkforceSsoEnrollmentLoginResume || ! $resume->isUsable()) {
            return null;
        }

        if (strtolower($resume->tenant_host) !== strtolower($tenantHost)) {
            return null;
        }

        if (! SsoSecretCrypto::proofsMatch((string) $resume->browser_binding_secret_hash, $binding)) {
            return null;
        }

        $invitation = WorkforceSsoEnrollmentInvitation::query()
            ->whereKey($resume->invitation_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $invitation instanceof WorkforceSsoEnrollmentInvitation || ! $invitation->isPending()) {
            return null;
        }

        if (strtolower($invitation->tenant_host) !== strtolower($tenantHost)) {
            return null;
        }

        $updated = WorkforceSsoEnrollmentLoginResume::query()
            ->whereKey($resume->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        return $invitation;
    }

    /**
     * Resolve resume token from query or cookie without consuming (for post-login redirect).
     */
    public function peekTokenFromRequest(Request $request): string
    {
        $fromQuery = trim((string) $request->query('c', ''));
        if ($fromQuery !== '') {
            return $fromQuery;
        }

        return (string) $request->cookie(SsoBrowserBindingCookieFactory::ENROLLMENT_LOGIN_RESUME, '');
    }

    public function resumeUrl(Tenant $tenant, string $plainToken): string
    {
        $scheme = app(\App\Support\Tenancy\TenantAddressingProfile::class)->canonicalScheme() ?: 'https';
        $host = strtolower((string) parse_url(app(\App\Http\Auth\TenantEntryUrlResolver::class)->entryUrl($tenant), PHP_URL_HOST));

        return $scheme.'://'.$host.'/security/sso/enrollment/resume?c='.rawurlencode($plainToken);
    }
}
