<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Mail\CanonicalEmailChangeMail;
use Modules\Identity\Models\SsoIdentityResetTransaction;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserCanonicalEmailChangeRequest;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Sso\SsoCanonicalEmail;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 GAP-011: Admin-controlled canonical User Email change.
 * Linked SSO Users require Reset SSO coupling; mailbox proof required.
 */
class CanonicalEmailChangeService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected ResetSsoService $resetSso,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * @return array{request: UserCanonicalEmailChangeRequest, verify_url?: string, plain_token?: string}
     */
    public function initiate(
        Tenant $tenant,
        TenantUser $actor,
        TenantUser $target,
        string $proposedEmail,
    ): array {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_EMAIL,
            $target,
        );

        $proposed = SsoCanonicalEmail::normalize($proposedEmail);
        if ($proposed === '' || ! filter_var($proposed, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['A valid email address is required.'],
            ]);
        }

        if (SsoCanonicalEmail::equals($proposed, (string) $target->email)) {
            throw ValidationException::withMessages([
                'email' => ['Proposed email matches the current canonical email.'],
            ]);
        }

        $duplicate = TenantUser::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('email', $proposed)
            ->where('id', '!=', $target->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => ['This email is already used in this organization.'],
            ]);
        }

        $hasSso = false;
        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }
        try {
            $hasSso = TenantUserIdentity::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $target->id)
                ->whereIn('binding_role', [
                    TenantUserIdentity::ROLE_CURRENT,
                    TenantUserIdentity::ROLE_CANDIDATE,
                ])
                ->exists();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }

        $plain = Str::random(64);
        $hours = max(1, (int) config('identity.security.email_change_ttl_hours', 24));

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $request = UserCanonicalEmailChangeRequest::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $target->id,
                'initiated_by_user_id' => $actor->id,
                'current_email' => SsoCanonicalEmail::normalize((string) $target->email),
                'proposed_email' => $proposed,
                'token_hash' => hash('sha256', $plain),
                'status' => UserCanonicalEmailChangeRequest::STATUS_PENDING,
                'requires_reset_sso' => $hasSso,
                'expires_at' => now()->addHours($hours),
            ]);

            $verifyUrl = url('/security/email-change/verify/'.$plain);

            try {
                Mail::to($proposed)->send(new CanonicalEmailChangeMail(
                    tenant: $tenant,
                    proposedEmail: $proposed,
                    verifyUrl: $verifyUrl,
                    expiresAt: $request->expires_at,
                ));
            } catch (\Throwable) {
                // Pre-production: still return token for lab verification when mailer unavailable.
            }

            $this->audit->log('auth_admin.email_change.initiated', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => UserCanonicalEmailChangeRequest::class,
                'auditable_id' => (string) $request->id,
                'new_values' => [
                    'target_user_id' => (string) $target->id,
                    'requires_reset_sso' => $hasSso,
                    // Do not log full emails if redaction preferred — store domain only.
                    'proposed_domain' => substr(strrchr($proposed, '@') ?: '', 1),
                ],
            ]);

            return [
                'request' => $request,
                'verify_url' => $verifyUrl,
                'plain_token' => $plain,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * Mailbox proof — does not yet finalize identity if Reset SSO required.
     */
    public function verifyMailbox(string $plainToken): UserCanonicalEmailChangeRequest
    {
        $hash = hash('sha256', $plainToken);
        $request = UserCanonicalEmailChangeRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('token_hash', $hash)
            ->first();

        if (! $request || ! $request->isPending()) {
            throw ValidationException::withMessages([
                'token' => ['This email change link is invalid or has expired.'],
            ]);
        }

        $tenant = Tenant::query()->findOrFail($request->tenant_id);
        tenancy()->initialize($tenant);

        try {
            $request->update([
                'status' => UserCanonicalEmailChangeRequest::STATUS_VERIFIED,
                'verified_at' => now(),
            ]);

            $this->audit->log('auth_admin.email_change.mailbox_verified', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => UserCanonicalEmailChangeRequest::class,
                'auditable_id' => (string) $request->id,
            ]);

            return $request->fresh();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Finalize after mailbox verified. Linked Users must have Reset SSO initiated (same EUID does not skip).
     *
     * @return array{user: TenantUser, reset_transaction?: SsoIdentityResetTransaction}
     */
    public function complete(
        Tenant $tenant,
        TenantUser $actor,
        UserCanonicalEmailChangeRequest $request,
    ): array {
        // Freshness already consumed on initiate; require gate again for complete if still verified.
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_EMAIL,
            TenantUser::withoutGlobalScope('tenant')->findOrFail($request->user_id),
            consumeFreshness: true,
        );

        if ($request->status !== UserCanonicalEmailChangeRequest::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'email' => ['Mailbox verification must complete before the email becomes authoritative.'],
            ]);
        }

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($tenant, $actor, $request) {
                $user = TenantUser::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($request->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $userId = (string) $user->id;
                $user->forceFill(['email' => $request->proposed_email])->save();

                $result = ['user' => $user->fresh()];

                if ($request->requires_reset_sso) {
                    // Same EUID does NOT skip Reset SSO — start re-verification lifecycle.
                    $reset = $this->resetSso->initiate(
                        $tenant,
                        $actor,
                        $user,
                        compromisedCurrent: false,
                        purpose: SsoIdentityResetTransaction::PURPOSE_EMAIL_CHANGE,
                        consumeFreshness: false,
                        skipGate: true,
                    );
                    $txn = $this->resetSso->markSameEuidReverification($tenant, $reset['transaction']);
                    $request->update([
                        'reset_transaction_id' => $txn->id,
                        'status' => UserCanonicalEmailChangeRequest::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
                    $result['reset_transaction'] = $txn;
                } else {
                    $request->update([
                        'status' => UserCanonicalEmailChangeRequest::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
                }

                $this->audit->log('auth_admin.email_change.completed', [
                    'tenant_id' => (string) $tenant->id,
                    'auditable_type' => UserCanonicalEmailChangeRequest::class,
                    'auditable_id' => (string) $request->id,
                    'new_values' => [
                        'user_id' => $userId,
                        'requires_reset_sso' => (bool) $request->requires_reset_sso,
                    ],
                ]);

                return $result;
            });
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }
}
