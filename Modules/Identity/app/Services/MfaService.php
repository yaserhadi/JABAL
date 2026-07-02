<?php

namespace Modules\Identity\Services;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Models\TenantSecurityPolicy;
use Modules\Identity\Models\UserMfa;
use Modules\Identity\Models\UserMfaRecoveryCode;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;

/** Tenant-layer MFA (AVM — ADR-0007 R8). No central auth artifacts. */
class MfaService
{
    public function __construct(
        protected SecurityFeatureGate $featureGate,
        protected Google2FA $google2fa
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

        if ($this->featureGate->featureEnabled($tenant, 'mfa_required')) {
            return true;
        }

        $policy = TenantSecurityPolicy::query()->where('tenant_id', $tenant->id)->first();

        return (bool) ($policy?->mfa_required ?? false);
    }

    public function userHasConfirmedMfa(User $user): bool
    {
        $record = UserMfa::query()->where('user_id', $user->id)->first();

        return $record?->isConfirmed() ?? false;
    }

    public function sessionIsMfaVerified(): bool
    {
        return session('mfa_verified_at') !== null;
    }

    /**
     * @return array{secret: string, qr_url: string}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        UserMfa::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'confirmed_at' => null]
        );

        $qrUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        return ['secret' => $secret, 'qr_url' => $qrUrl];
    }

    /**
     * @return array<int, string>
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        $record = UserMfa::query()->where('user_id', $user->id)->firstOrFail();
        if (! $this->google2fa->verifyKey($record->secret, $code)) {
            abort(422, 'Invalid verification code.');
        }

        $record->confirmed_at = now();
        $record->save();

        $plainCodes = $this->regenerateRecoveryCodes($user);
        session(['mfa_verified_at' => now()->toIso8601String()]);
        MfaVerificationContext::markVerified('login');

        app(AuditLoggerInterface::class)->log('user_mfa.enrolled', [
            'tenant_id' => tenancy()->tenant?->id,
            'auditable_type' => UserMfa::class,
            'auditable_id' => $user->id,
        ]);

        return $plainCodes;
    }

    public function verifyChallenge(User $user, string $code, string $stepUpPurpose = 'login'): bool
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
    public function verifyCodeForGrant(User $user, string $code): bool
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

    public function resetForUser(User $user): void
    {
        UserMfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        UserMfa::query()->where('user_id', $user->id)->delete();
        session()->forget('mfa_verified_at');
        MfaVerificationContext::clear();

        app(AuditLoggerInterface::class)->log('user_mfa.reset', [
            'tenant_id' => tenancy()->tenant?->id,
            'auditable_type' => UserMfa::class,
            'auditable_id' => $user->id,
        ]);
    }

    public function setTenantMfaRequired(Tenant $tenant, bool $required): TenantSecurityPolicy
    {
        if ($required && ! $this->isMfaAvailable($tenant)) {
            abort(403, 'MFA is not available for this tenant plan.');
        }

        return TenantSecurityPolicy::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['mfa_required' => $required]
        );
    }

    /**
     * @return array<int, string>
     */
    protected function regenerateRecoveryCodes(User $user): array
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

    protected function consumeRecoveryCode(User $user, string $code, string $stepUpPurpose): bool
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

    protected function consumeRecoveryCodeForGrant(User $user, string $code): bool
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
