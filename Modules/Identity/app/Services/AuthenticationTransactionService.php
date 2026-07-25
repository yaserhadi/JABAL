<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoEnrollmentContinuation;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use RuntimeException;

/**
 * BK-082 Workstream 2: central Authentication Transaction + Tenant Handoff store (DEC-0024).
 *
 * Does not set cookies, call Auth::login, or run OIDC callback HTTP. Store + CAS only.
 */
class AuthenticationTransactionService
{
    public function transactionTtlSeconds(): int
    {
        return (int) config('identity.sso.auth_transaction_ttl', 600);
    }

    public function handoffTtlSeconds(): int
    {
        return (int) config('identity.sso.handoff_ttl', 60);
    }

    public function enrollmentContinuationTtlSeconds(): int
    {
        return (int) config('identity.sso.enrollment_continuation_ttl', 60);
    }

    public function concurrencyLimit(): int
    {
        return (int) config('identity.sso.auth_transaction_concurrency', 3);
    }

    /**
     * @param  array{
     *   tenant_id: string,
     *   destination_host: string,
     *   addressing_profile: string,
     *   post_login_path: string,
     *   idp_configuration_version_id: string,
     *   expected_issuer?: ?string,
     *   domain_id?: ?string,
     *   purpose?: string,
     *   enrollment_invitation_id?: ?string,
     *   intended_user_id?: ?string,
     *   tenant_continuation_secret?: string,
     * }  $input
     * @return array{
     *   transaction: SsoAuthenticationTransaction,
     *   state: string,
     *   nonce: string,
     *   pkce_verifier: string,
     *   pkce_challenge: string,
     *   tenant_continuation_secret: string,
     *   initiation_reference: string,
     * }
     */
    public function create(array $input): array
    {
        $tenantId = (string) $input['tenant_id'];
        $idpVersionId = (string) $input['idp_configuration_version_id'];
        $continuationSecret = $input['tenant_continuation_secret'] ?? SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $continuationHash = SsoSecretCrypto::proof($continuationSecret);

        $stateLookup = (string) Str::uuid();
        $stateSecret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::STATE_SECRET_BYTES);
        $initiationLookup = (string) Str::uuid();
        $initiationSecret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::INITIATION_SECRET_BYTES);
        $nonce = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::NONCE_BYTES);
        $pkceVerifier = SsoSecretCrypto::pkceCodeVerifier();
        $correlationId = (string) Str::uuid();

        $transaction = DB::connection('central')->transaction(function () use (
            $input,
            $tenantId,
            $idpVersionId,
            $continuationHash,
            $stateLookup,
            $stateSecret,
            $initiationLookup,
            $initiationSecret,
            $nonce,
            $pkceVerifier,
            $correlationId,
        ) {
            $this->enforceConcurrency($tenantId, $idpVersionId, $continuationHash);

            return SsoAuthenticationTransaction::query()->create([
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'domain_id' => $input['domain_id'] ?? null,
                'destination_host' => (string) $input['destination_host'],
                'addressing_profile' => (string) $input['addressing_profile'],
                'post_login_path' => (string) $input['post_login_path'],
                'idp_configuration_version_id' => $idpVersionId,
                'expected_issuer' => $input['expected_issuer'] ?? null,
                'purpose' => $input['purpose'] ?? SsoAuthenticationTransaction::PURPOSE_ORDINARY,
                'enrollment_invitation_id' => $input['enrollment_invitation_id'] ?? null,
                'intended_user_id' => $input['intended_user_id'] ?? null,
                'state_lookup' => $stateLookup,
                'state_secret_hash' => SsoSecretCrypto::proof($stateSecret),
                'initiation_lookup' => $initiationLookup,
                'initiation_secret_hash' => SsoSecretCrypto::proof($initiationSecret),
                'state_secret_encrypted' => Crypt::encryptString($stateSecret),
                'nonce_encrypted' => Crypt::encryptString($nonce),
                'pkce_verifier_encrypted' => Crypt::encryptString($pkceVerifier),
                'auth_binding_secret_hash' => null,
                'tenant_continuation_secret_hash' => $continuationHash,
                'status' => SsoAuthenticationTransaction::STATUS_PENDING,
                'expires_at' => now()->addSeconds($this->transactionTtlSeconds()),
            ]);
        });

        return [
            'transaction' => $transaction,
            'state' => $stateLookup.'.'.$stateSecret,
            'nonce' => $nonce,
            'pkce_verifier' => $pkceVerifier,
            'pkce_challenge' => SsoSecretCrypto::pkceChallengeS256($pkceVerifier),
            'tenant_continuation_secret' => $continuationSecret,
            'initiation_reference' => $initiationLookup.'.'.$initiationSecret,
        ];
    }

    public function findByInitiationReference(string $reference): ?SsoAuthenticationTransaction
    {
        [$lookup, $secret] = $this->splitOpaquePair($reference);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        $transaction = SsoAuthenticationTransaction::query()->where('initiation_lookup', $lookup)->first();

        if (! $transaction || $transaction->secretsErased()) {
            return null;
        }

        if (! is_string($transaction->initiation_secret_hash) || $transaction->initiation_secret_hash === '') {
            return null;
        }

        if (! SsoSecretCrypto::proofsMatch((string) $transaction->initiation_secret_hash, $secret)) {
            return null;
        }

        return $transaction;
    }

    /**
     * Recover authorize-request materials for Auth Host initiate (not for browser authority).
     *
     * @return array{state: string, nonce: string, pkce_challenge: string}|null
     */
    public function authorizationMaterials(SsoAuthenticationTransaction $transaction): ?array
    {
        if ($transaction->secretsErased()) {
            return null;
        }

        if (! is_string($transaction->state_secret_encrypted) || $transaction->state_secret_encrypted === '') {
            return null;
        }

        $stateSecret = Crypt::decryptString($transaction->state_secret_encrypted);
        $nonce = $this->decryptNonce($transaction);
        $pkceVerifier = $this->decryptPkceVerifier($transaction);

        if ($nonce === null || $pkceVerifier === null || $stateSecret === '') {
            return null;
        }

        return [
            'state' => $transaction->state_lookup.'.'.$stateSecret,
            'nonce' => $nonce,
            'pkce_challenge' => SsoSecretCrypto::pkceChallengeS256($pkceVerifier),
        ];
    }

    public function attachAuthBinding(SsoAuthenticationTransaction $transaction, string $authBindingSecret): SsoAuthenticationTransaction
    {
        return DB::connection('central')->transaction(function () use ($transaction, $authBindingSecret) {
            $locked = SsoAuthenticationTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->auth_binding_secret_hash !== null) {
                throw new LogicException('Auth binding already attached.');
            }

            if (! in_array($locked->status, [
                SsoAuthenticationTransaction::STATUS_PENDING,
                SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            ], true)) {
                throw new LogicException('Cannot attach Auth binding in status '.$locked->status);
            }

            $locked->forceFill([
                'auth_binding_secret_hash' => SsoSecretCrypto::proof($authBindingSecret),
                'status' => SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            ])->save();

            return $locked->fresh();
        });
    }

    public function authBindingMatches(SsoAuthenticationTransaction $transaction, string $authBindingSecret): bool
    {
        if (! is_string($transaction->auth_binding_secret_hash) || $transaction->auth_binding_secret_hash === '') {
            return false;
        }

        return SsoSecretCrypto::proofsMatch((string) $transaction->auth_binding_secret_hash, $authBindingSecret);
    }

    public function failTerminal(SsoAuthenticationTransaction $transaction, string $reason): SsoAuthenticationTransaction
    {
        return DB::connection('central')->transaction(function () use ($transaction, $reason) {
            $locked = SsoAuthenticationTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $this->markFailed($locked, $reason);

            return $locked->fresh();
        });
    }

    public function findByState(string $state): ?SsoAuthenticationTransaction
    {
        [$lookup, $secret] = $this->splitOpaquePair($state);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        $transaction = SsoAuthenticationTransaction::query()->where('state_lookup', $lookup)->first();

        if (! $transaction || $transaction->secretsErased()) {
            return null;
        }

        if (! SsoSecretCrypto::proofsMatch((string) $transaction->state_secret_hash, $secret)) {
            return null;
        }

        return $transaction;
    }

    /**
     * Atomic callback reservation (CAS). Returns null if already reserved/consumed/expired.
     */
    public function reserveCallback(string $transactionId): ?SsoAuthenticationTransaction
    {
        return DB::connection('central')->transaction(function () use ($transactionId) {
            $locked = SsoAuthenticationTransaction::query()->whereKey($transactionId)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            if ($locked->isExpired()) {
                $this->markFailed($locked, 'expired');

                return null;
            }

            if ($locked->status !== SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK) {
                return null;
            }

            $updated = SsoAuthenticationTransaction::query()
                ->whereKey($locked->id)
                ->where('status', SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK)
                ->update([
                    'status' => SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED,
                    'callback_reserved_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            return $locked->fresh();
        });
    }

    /**
     * @param  array{
     *   user_id?: ?string,
     *   identity_link_id?: ?string,
     *   assurance_evidence?: ?array<string, mixed>,
     * }  $payload
     * @return array{handoff: SsoTenantHandoff, reference: string}
     */
    public function issueHandoff(SsoAuthenticationTransaction $transaction, array $payload = []): array
    {
        $secret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::HANDOFF_SECRET_BYTES);

        $handoff = DB::connection('central')->transaction(function () use ($transaction, $payload, $secret) {
            $locked = SsoAuthenticationTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED) {
                throw new LogicException('Handoff requires callback_reserved status.');
            }

            if ($locked->isExpired()) {
                $this->markFailed($locked, 'expired');
                throw new RuntimeException('Authentication transaction expired.');
            }

            if ($locked->tenant_continuation_secret_hash === null) {
                throw new LogicException('Tenant continuation binding required before Handoff.');
            }

            $handoff = SsoTenantHandoff::query()->create([
                'correlation_id' => $locked->correlation_id,
                'transaction_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'domain_id' => $locked->domain_id,
                'destination_host' => $locked->destination_host,
                'audience' => SsoTenantHandoff::AUDIENCE_TENANT_HOST,
                'post_login_path' => $locked->post_login_path,
                'secret_hash' => SsoSecretCrypto::proof($secret),
                'tenant_continuation_secret_hash' => $locked->tenant_continuation_secret_hash,
                'user_id' => $payload['user_id'] ?? null,
                'identity_link_id' => $payload['identity_link_id'] ?? null,
                'assurance_evidence' => $payload['assurance_evidence'] ?? null,
                'status' => SsoTenantHandoff::STATUS_ISSUED,
                'expires_at' => now()->addSeconds($this->handoffTtlSeconds()),
            ]);

            $this->eraseTransactionRecoverableSecrets($locked);
            $locked->forceFill([
                'status' => SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED,
            ])->save();

            return $handoff;
        });

        return [
            'handoff' => $handoff,
            'reference' => $handoff->id.'.'.$secret,
        ];
    }

    /**
     * BK-099: issue enrollment continuation with encrypted issuer+subject (not login Handoff).
     *
     * Browser binding reuses Authentication Transaction tenant_continuation_secret_hash
     * (Tenant Host cookie) — Auth Host never holds the plaintext binding.
     *
     * @param  array{
     *   issuer: string,
     *   subject: string,
     *   invitation_id: string,
     *   intended_user_id: string,
     * }  $payload
     * @return array{continuation: SsoEnrollmentContinuation, reference: string}
     */
    public function issueEnrollmentContinuation(SsoAuthenticationTransaction $transaction, array $payload): array
    {
        $secret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::HANDOFF_SECRET_BYTES);
        $lookup = (string) Str::uuid();

        $continuation = DB::connection('central')->transaction(function () use ($transaction, $payload, $secret, $lookup) {
            $locked = SsoAuthenticationTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->purpose !== SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT) {
                throw new LogicException('Enrollment continuation requires workforce enrollment purpose.');
            }

            if ($locked->status !== SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED) {
                throw new LogicException('Enrollment continuation requires callback_reserved status.');
            }

            if ($locked->isExpired()) {
                $this->markFailed($locked, 'expired');
                throw new RuntimeException('Authentication transaction expired.');
            }

            if ($locked->tenant_continuation_secret_hash === null) {
                throw new LogicException('Tenant continuation binding required before enrollment continuation.');
            }

            $continuation = SsoEnrollmentContinuation::query()->create([
                'transaction_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'invitation_id' => (string) $payload['invitation_id'],
                'intended_user_id' => (string) $payload['intended_user_id'],
                'idp_configuration_version_id' => $locked->idp_configuration_version_id,
                'destination_host' => $locked->destination_host,
                'issuer_encrypted' => Crypt::encryptString((string) $payload['issuer']),
                'subject_encrypted' => Crypt::encryptString((string) $payload['subject']),
                'lookup' => $lookup,
                'secret_hash' => SsoSecretCrypto::proof($secret),
                'browser_binding_secret_hash' => $locked->tenant_continuation_secret_hash,
                'status' => SsoEnrollmentContinuation::STATUS_PENDING,
                'expires_at' => now()->addSeconds($this->enrollmentContinuationTtlSeconds()),
            ]);

            $this->eraseTransactionRecoverableSecrets($locked);
            $locked->forceFill([
                'status' => SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED,
            ])->save();

            return $continuation;
        });

        return [
            'continuation' => $continuation,
            'reference' => $lookup.'.'.$secret,
        ];
    }

    public function findEnrollmentContinuationByReference(string $reference): ?SsoEnrollmentContinuation
    {
        [$lookup, $secret] = $this->splitOpaquePair($reference);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        $continuation = SsoEnrollmentContinuation::query()->where('lookup', $lookup)->first();

        if (! $continuation || $continuation->status !== SsoEnrollmentContinuation::STATUS_PENDING) {
            return null;
        }

        if ($continuation->isExpired()) {
            return null;
        }

        if (! SsoSecretCrypto::proofsMatch((string) $continuation->secret_hash, $secret)) {
            return null;
        }

        return $continuation;
    }

    /**
     * Atomic enrollment continuation consume. Returns decrypted issuer/subject payload or null.
     *
     * @return array{
     *   continuation: SsoEnrollmentContinuation,
     *   issuer: string,
     *   subject: string,
     * }|null
     */
    public function consumeEnrollmentContinuation(
        string $reference,
        string $tenantId,
        string $destinationHost,
        ?string $browserBindingSecret = null,
    ): ?array {
        [$lookup, $secret] = $this->splitOpaquePair($reference);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        return DB::connection('central')->transaction(function () use ($lookup, $secret, $tenantId, $destinationHost, $browserBindingSecret) {
            $locked = SsoEnrollmentContinuation::query()->where('lookup', $lookup)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SsoEnrollmentContinuation::STATUS_PENDING) {
                return null;
            }

            if ($locked->isExpired()) {
                $locked->forceFill([
                    'status' => SsoEnrollmentContinuation::STATUS_EXPIRED,
                ])->save();

                return null;
            }

            if ($locked->tenant_id !== $tenantId || $locked->destination_host !== $destinationHost) {
                return null;
            }

            if (! SsoSecretCrypto::proofsMatch((string) $locked->secret_hash, $secret)) {
                return null;
            }

            if (is_string($locked->browser_binding_secret_hash) && $locked->browser_binding_secret_hash !== '') {
                if (! is_string($browserBindingSecret) || $browserBindingSecret === '') {
                    return null;
                }
                if (! SsoSecretCrypto::proofsMatch((string) $locked->browser_binding_secret_hash, $browserBindingSecret)) {
                    return null;
                }
            }

            $issuer = Crypt::decryptString((string) $locked->issuer_encrypted);
            $subject = Crypt::decryptString((string) $locked->subject_encrypted);

            $updated = SsoEnrollmentContinuation::query()
                ->whereKey($locked->id)
                ->where('status', SsoEnrollmentContinuation::STATUS_PENDING)
                ->update([
                    'status' => SsoEnrollmentContinuation::STATUS_CONSUMED,
                    'consumed_at' => now(),
                    'issuer_encrypted' => '',
                    'subject_encrypted' => '',
                    'secret_hash' => '',
                    'browser_binding_secret_hash' => null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $fresh = $locked->fresh();

            $txn = SsoAuthenticationTransaction::query()->whereKey($fresh->transaction_id)->lockForUpdate()->first();
            if ($txn) {
                $txn->forceFill([
                    'status' => SsoAuthenticationTransaction::STATUS_CONSUMED,
                    'consumed_at' => now(),
                ])->save();
            }

            return [
                'continuation' => $fresh,
                'issuer' => $issuer,
                'subject' => $subject,
            ];
        });
    }

    /**
     * Read-only Handoff proof check (no consume). Used for D12 policy before irreversible consume.
     */
    public function peekHandoff(string $reference): ?SsoTenantHandoff
    {
        [$lookup, $secret] = $this->splitOpaquePair($reference);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        $handoff = SsoTenantHandoff::query()->whereKey($lookup)->first();

        if (! $handoff || $handoff->status !== SsoTenantHandoff::STATUS_ISSUED) {
            return null;
        }

        if ($handoff->isExpired() || $handoff->secretsErased()) {
            return null;
        }

        if (! SsoSecretCrypto::proofsMatch((string) $handoff->secret_hash, $secret)) {
            return null;
        }

        return $handoff;
    }

    /**
     * Atomic Handoff consume. Returns null on replay/mismatch/expiry.
     */
    public function consumeHandoff(string $reference, string $tenantId, string $destinationHost, string $continuationSecret): ?SsoTenantHandoff
    {
        [$lookup, $secret] = $this->splitOpaquePair($reference);

        if ($lookup === null || $secret === null || ! Str::isUuid($lookup)) {
            return null;
        }

        return DB::connection('central')->transaction(function () use ($lookup, $secret, $tenantId, $destinationHost, $continuationSecret) {
            $locked = SsoTenantHandoff::query()->whereKey($lookup)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SsoTenantHandoff::STATUS_ISSUED) {
                return null;
            }

            if ($locked->isExpired()) {
                $locked->forceFill([
                    'status' => SsoTenantHandoff::STATUS_EXPIRED,
                    'failure_reason' => 'expired',
                ])->save();
                $this->eraseHandoffSecrets($locked);

                return null;
            }

            if ($locked->tenant_id !== $tenantId || $locked->destination_host !== $destinationHost) {
                return null;
            }

            if (! SsoSecretCrypto::proofsMatch((string) $locked->secret_hash, $secret)) {
                return null;
            }

            if (! SsoSecretCrypto::proofsEqual(
                (string) $locked->tenant_continuation_secret_hash,
                SsoSecretCrypto::proof($continuationSecret),
            )) {
                return null;
            }

            $updated = SsoTenantHandoff::query()
                ->whereKey($locked->id)
                ->where('status', SsoTenantHandoff::STATUS_ISSUED)
                ->update([
                    'status' => SsoTenantHandoff::STATUS_CONSUMED,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $fresh = $locked->fresh();
            $this->eraseHandoffSecrets($fresh);

            $txn = SsoAuthenticationTransaction::query()->whereKey($fresh->transaction_id)->lockForUpdate()->first();
            if ($txn) {
                $txn->forceFill([
                    'status' => SsoAuthenticationTransaction::STATUS_CONSUMED,
                    'consumed_at' => now(),
                ])->save();
            }

            return $fresh->fresh();
        });
    }

    public function decryptNonce(SsoAuthenticationTransaction $transaction): ?string
    {
        if ($transaction->secretsErased() || ! is_string($transaction->nonce_encrypted) || $transaction->nonce_encrypted === '') {
            return null;
        }

        return Crypt::decryptString($transaction->nonce_encrypted);
    }

    public function decryptPkceVerifier(SsoAuthenticationTransaction $transaction): ?string
    {
        if ($transaction->secretsErased() || ! is_string($transaction->pkce_verifier_encrypted) || $transaction->pkce_verifier_encrypted === '') {
            return null;
        }

        return Crypt::decryptString($transaction->pkce_verifier_encrypted);
    }

    protected function enforceConcurrency(string $tenantId, string $idpVersionId, string $continuationHash): void
    {
        $activeStatuses = [
            SsoAuthenticationTransaction::STATUS_PENDING,
            SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
        ];

        $active = SsoAuthenticationTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('idp_configuration_version_id', $idpVersionId)
            ->where('tenant_continuation_secret_hash', $continuationHash)
            ->whereIn('status', $activeStatuses)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $limit = $this->concurrencyLimit();

        while ($active->count() >= $limit) {
            $oldest = $active->first();
            if (! $oldest) {
                break;
            }

            $oldest->forceFill([
                'status' => SsoAuthenticationTransaction::STATUS_SUPERSEDED,
                'failure_reason' => 'concurrency_limit',
            ])->save();
            $this->eraseTransactionRecoverableSecrets($oldest);
            $active = $active->slice(1)->values();
        }
    }

    /**
     * BK-082 WS7: idempotent expiry + secret erase for stale txn/handoff rows (D21).
     *
     * @return array{transactions_expired: int, handoffs_expired: int, secrets_erased: int}
     */
    public function expireAndEraseStale(): array
    {
        $transactionsExpired = 0;
        $handoffsExpired = 0;
        $secretsErased = 0;

        return DB::connection('central')->transaction(function () use (&$transactionsExpired, &$handoffsExpired, &$secretsErased) {
            $openTxnStatuses = [
                SsoAuthenticationTransaction::STATUS_PENDING,
                SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
                SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED,
                SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED,
            ];

            $staleTxns = SsoAuthenticationTransaction::query()
                ->whereIn('status', $openTxnStatuses)
                ->where('expires_at', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($staleTxns as $txn) {
                $txn->forceFill([
                    'status' => SsoAuthenticationTransaction::STATUS_EXPIRED,
                    'failure_reason' => 'expired',
                ])->save();
                if (! $txn->secretsErased()) {
                    $this->eraseTransactionRecoverableSecrets($txn);
                    $secretsErased++;
                }
                $transactionsExpired++;
            }

            // Terminal rows that still hold recoverable secrets.
            $terminalWithSecrets = SsoAuthenticationTransaction::query()
                ->whereIn('status', [
                    SsoAuthenticationTransaction::STATUS_FAILED,
                    SsoAuthenticationTransaction::STATUS_EXPIRED,
                    SsoAuthenticationTransaction::STATUS_SUPERSEDED,
                    SsoAuthenticationTransaction::STATUS_CONSUMED,
                    SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED,
                ])
                ->whereNull('secrets_erased_at')
                ->lockForUpdate()
                ->limit(500)
                ->get();

            foreach ($terminalWithSecrets as $txn) {
                $this->eraseTransactionRecoverableSecrets($txn);
                $secretsErased++;
            }

            $staleHandoffs = SsoTenantHandoff::query()
                ->where('status', SsoTenantHandoff::STATUS_ISSUED)
                ->where('expires_at', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($staleHandoffs as $handoff) {
                $handoff->forceFill([
                    'status' => SsoTenantHandoff::STATUS_EXPIRED,
                    'failure_reason' => 'expired',
                ])->save();
                if (! $handoff->secretsErased()) {
                    $this->eraseHandoffSecrets($handoff);
                    $secretsErased++;
                }
                $handoffsExpired++;
            }

            $consumedWithSecrets = SsoTenantHandoff::query()
                ->whereIn('status', [
                    SsoTenantHandoff::STATUS_CONSUMED,
                    SsoTenantHandoff::STATUS_EXPIRED,
                    SsoTenantHandoff::STATUS_FAILED,
                ])
                ->whereNull('secrets_erased_at')
                ->lockForUpdate()
                ->limit(500)
                ->get();

            foreach ($consumedWithSecrets as $handoff) {
                $this->eraseHandoffSecrets($handoff);
                $secretsErased++;
            }

            return [
                'transactions_expired' => $transactionsExpired,
                'handoffs_expired' => $handoffsExpired,
                'secrets_erased' => $secretsErased,
            ];
        });
    }

    /**
     * Cancel open transactions + issued handoffs for a tenant (WS8 security-disable / kill switch).
     *
     * @return int Number of records cancelled
     */
    public function cancelOpenForTenant(string $tenantId, string $reason): int
    {
        return (int) DB::connection('central')->transaction(function () use ($tenantId, $reason) {
            $count = 0;
            $openTxn = SsoAuthenticationTransaction::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', $this->openTransactionStatuses())
                ->lockForUpdate()
                ->get();

            foreach ($openTxn as $txn) {
                $this->markFailed($txn, $reason);
                $count++;
            }

            $openHandoffs = SsoTenantHandoff::query()
                ->where('tenant_id', $tenantId)
                ->where('status', SsoTenantHandoff::STATUS_ISSUED)
                ->lockForUpdate()
                ->get();

            foreach ($openHandoffs as $handoff) {
                $handoff->forceFill([
                    'status' => SsoTenantHandoff::STATUS_FAILED,
                    'failure_reason' => $reason,
                ])->save();
                if (! $handoff->secretsErased()) {
                    $this->eraseHandoffSecrets($handoff);
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * @return int Number of records cancelled
     */
    public function cancelOpenForVersion(string $tenantId, string $versionId, string $reason): int
    {
        return (int) DB::connection('central')->transaction(function () use ($tenantId, $versionId, $reason) {
            $count = 0;
            $openTxn = SsoAuthenticationTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('idp_configuration_version_id', $versionId)
                ->whereIn('status', $this->openTransactionStatuses())
                ->lockForUpdate()
                ->get();

            $txnIds = [];
            foreach ($openTxn as $txn) {
                $txnIds[] = (string) $txn->id;
                $this->markFailed($txn, $reason);
                $count++;
            }

            if ($txnIds !== []) {
                $openHandoffs = SsoTenantHandoff::query()
                    ->whereIn('transaction_id', $txnIds)
                    ->where('status', SsoTenantHandoff::STATUS_ISSUED)
                    ->lockForUpdate()
                    ->get();

                foreach ($openHandoffs as $handoff) {
                    $handoff->forceFill([
                        'status' => SsoTenantHandoff::STATUS_FAILED,
                        'failure_reason' => $reason,
                    ])->save();
                    if (! $handoff->secretsErased()) {
                        $this->eraseHandoffSecrets($handoff);
                    }
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * @return int Number of records cancelled
     */
    public function cancelOpenEverywhere(string $reason): int
    {
        return (int) DB::connection('central')->transaction(function () use ($reason) {
            $count = 0;
            $openTxn = SsoAuthenticationTransaction::query()
                ->whereIn('status', $this->openTransactionStatuses())
                ->lockForUpdate()
                ->get();

            foreach ($openTxn as $txn) {
                $this->markFailed($txn, $reason);
                $count++;
            }

            $openHandoffs = SsoTenantHandoff::query()
                ->where('status', SsoTenantHandoff::STATUS_ISSUED)
                ->lockForUpdate()
                ->get();

            foreach ($openHandoffs as $handoff) {
                $handoff->forceFill([
                    'status' => SsoTenantHandoff::STATUS_FAILED,
                    'failure_reason' => $reason,
                ])->save();
                if (! $handoff->secretsErased()) {
                    $this->eraseHandoffSecrets($handoff);
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * @return list<string>
     */
    protected function openTransactionStatuses(): array
    {
        return [
            SsoAuthenticationTransaction::STATUS_PENDING,
            SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED,
            SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED,
        ];
    }

    protected function eraseTransactionRecoverableSecrets(SsoAuthenticationTransaction $transaction): void
    {
        $transaction->forceFill([
            'state_secret_encrypted' => '',
            'nonce_encrypted' => '',
            'pkce_verifier_encrypted' => '',
            'secrets_erased_at' => now(),
        ])->save();
    }

    protected function eraseHandoffSecrets(SsoTenantHandoff $handoff): void
    {
        $handoff->forceFill([
            'secret_hash' => '',
            'secrets_erased_at' => now(),
        ])->save();
    }

    protected function markFailed(SsoAuthenticationTransaction $transaction, string $reason): void
    {
        $transaction->forceFill([
            'status' => $reason === 'expired'
                ? SsoAuthenticationTransaction::STATUS_EXPIRED
                : SsoAuthenticationTransaction::STATUS_FAILED,
            'failure_reason' => $reason,
        ])->save();
        $this->eraseTransactionRecoverableSecrets($transaction);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitOpaquePair(string $value): array
    {
        $parts = explode('.', $value, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }
}
