<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\TenantUser;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Models\UserMfa;
use Modules\Identity\Models\UserMfaRecoveryCode;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;

/** Tenant-layer MFA (AVM — ADR-0007 R8). No central auth artifacts. */
class MfaService
{
    /**
     * Set at password/SSO login when MFA is required and the user is not enrolled.
     * Existing authenticated sessions without this marker are not hijacked into enrollment
     * when an admin turns on tenant MFA Required (BK-127 #32).
     *
     * Cookie (not session-only): survives ConfigureApplicationRuntime session instance resets
     * under phpunit's array session driver while remaining scoped to the browser that just logged in.
     */
    public const SESSION_POST_LOGIN_ENROLLMENT = 'mfa_post_login_enrollment';

    public const COOKIE_POST_LOGIN_ENROLLMENT = 'jabal_mfa_enroll_gate';

    public function __construct(
        protected SecurityFeatureGate $featureGate,
        protected Google2FA $google2fa,
        protected SecurityPolicyService $securityPolicyService
    ) {}

    public function isMfaAvailable(Tenant $tenant): bool
    {
        return $this->featureGate->featureEnabled($tenant, 'mfa_available');
    }

    public function isMfaRequired(Tenant $tenant): bool
    {
        if (! $this->isMfaAvailable($tenant)) {
            return false;
        }

        // OD-3 / Option A: entitlement code `mfa_required` is NOT authoritative for ordinary-user require.
        // Ordinary-user requirement = Tenant security policy only (when MFA available).
        return $this->securityPolicyService->isMfaRequired($tenant);
    }

    public function userHasConfirmedMfa(TenantUser $user): bool
    {
        $record = UserMfa::query()->where('user_id', $user->id)->first();

        return $record?->isConfirmed() ?? false;
    }

    public function sessionIsMfaVerified(): bool
    {
        return session('mfa_verified_at') !== null;
    }

    public function markPostLoginEnrollmentRequired(): void
    {
        session([self::SESSION_POST_LOGIN_ENROLLMENT => true]);
    }

    /**
     * Cookie attached to the login redirect response (browser + HTTP tests).
     */
    public function postLoginEnrollmentCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            self::COOKIE_POST_LOGIN_ENROLLMENT,
            '1',
            120,
            '/',
            null,
            null,
            true,
            false,
            'lax'
        );
    }

    public function postLoginEnrollmentRequired(?\Illuminate\Http\Request $request = null): bool
    {
        if ((bool) session(self::SESSION_POST_LOGIN_ENROLLMENT)) {
            return true;
        }

        $request ??= request();

        return $request instanceof \Illuminate\Http\Request
            && (string) $request->cookie(self::COOKIE_POST_LOGIN_ENROLLMENT) === '1';
    }

    public function clearPostLoginEnrollmentRequired(): void
    {
        session()->forget(self::SESSION_POST_LOGIN_ENROLLMENT);
        cookie()->queue(cookie()->forget(self::COOKIE_POST_LOGIN_ENROLLMENT));
    }

    /**
     * After a successful authentication boundary: if policy requires MFA and the user
     * is not enrolled, mark the post-login gate and return the enroll URL; otherwise null.
     */
    public function enrollUrlAfterLoginIfRequired(Tenant $tenant, TenantUser $user): ?string
    {
        if (! $this->isMfaRequired($tenant) || $this->userHasConfirmedMfa($user)) {
            return null;
        }

        $this->markPostLoginEnrollmentRequired();

        return app(\App\Http\Auth\TenantEntryUrlResolver::class)
            ->namedRouteUrl('identity.mfa.enroll', $tenant);
    }

    /**
     * @return array{secret: string, qr_url: string, otpauth_uri: string}
     */
    public function beginEnrollment(TenantUser $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        UserMfa::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'confirmed_at' => null]
        );

        // otpauth:// URI for local QR rendering — never an external QR image service.
        $otpauthUri = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        return [
            'secret' => $secret,
            'qr_url' => $otpauthUri,
            'otpauth_uri' => $otpauthUri,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function confirmEnrollment(TenantUser $user, string $code): array
    {
        $record = UserMfa::query()->where('user_id', $user->id)->firstOrFail();
        if (! $this->google2fa->verifyKey($record->secret, $code)) {
            abort(422, 'Invalid verification code.');
        }

        $record->confirmed_at = now();
        $record->save();

        $plainCodes = $this->regenerateRecoveryCodes($user);
        $this->clearPostLoginEnrollmentRequired();
        session(['mfa_verified_at' => now()->toIso8601String()]);
        MfaVerificationContext::markVerified('login');

        app(AuditLoggerInterface::class)->log('user_mfa.enrolled', [
            'tenant_id' => tenancy()->tenant?->id,
            'auditable_type' => UserMfa::class,
            'auditable_id' => $user->id,
        ]);

        return $plainCodes;
    }

    public function verifyChallenge(TenantUser $user, string $code, string $stepUpPurpose = 'login'): bool
    {
        $record = UserMfa::query()->where('user_id', $user->id)->first();
        if (! $record || ! $record->isConfirmed()) {
            return false;
        }

        if ($this->google2fa->verifyKey($record->secret, $code)) {
            session(['mfa_verified_at' => now()->toIso8601String()]);
            MfaVerificationContext::markVerified($stepUpPurpose);

            return true;
        }

        return $this->consumeRecoveryCode($user, $code, $stepUpPurpose);
    }

    /** Stateless MFA verification for API token grant (DEC-0014). No session side effects. */
    public function verifyCodeForGrant(TenantUser $user, string $code): bool
    {
        $record = UserMfa::query()->where('user_id', $user->id)->first();
        if (! $record || ! $record->isConfirmed()) {
            return false;
        }

        if ($this->google2fa->verifyKey($record->secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCodeForGrant($user, $code);
    }

    public function resetForUser(TenantUser $user): void
    {
        $this->revokeEnrollmentRecords($user);
        session()->forget('mfa_verified_at');
        MfaVerificationContext::clear();

        app(AuditLoggerInterface::class)->log('user_mfa.reset', [
            'tenant_id' => tenancy()->tenant?->id,
            'auditable_type' => UserMfa::class,
            'auditable_id' => $user->id,
        ]);
    }

    /**
     * WAVE-4: Revoke MFA enrollment for a target User without mutating the actor's session.
     */
    public function revokeEnrollmentRecords(TenantUser $user): void
    {
        UserMfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        UserMfa::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @return array<int, string>
     */
    protected function regenerateRecoveryCodes(TenantUser $user): array
    {
        UserMfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        $plain = [];
        for ($i = 0; $i < 8; $i++) {
            $code = Str::upper(Str::random(10));
            $plain[] = $code;
            UserMfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $plain;
    }

    protected function consumeRecoveryCode(TenantUser $user, string $code, string $stepUpPurpose): bool
    {
        $codes = UserMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($codes as $row) {
            if (Hash::check($code, $row->code_hash)) {
                $row->used_at = now();
                $row->save();
                session(['mfa_verified_at' => now()->toIso8601String()]);
                MfaVerificationContext::markVerified($stepUpPurpose);

                return true;
            }
        }

        return false;
    }

    protected function consumeRecoveryCodeForGrant(TenantUser $user, string $code): bool
    {
        $codes = UserMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($codes as $row) {
            if (Hash::check($code, $row->code_hash)) {
                $row->used_at = now();
                $row->save();

                return true;
            }
        }

        return false;
    }
}
