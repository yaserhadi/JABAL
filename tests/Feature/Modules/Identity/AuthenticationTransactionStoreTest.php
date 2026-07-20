<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 Workstream 2 — central Authentication Transaction / Handoff store. */
class AuthenticationTransactionStoreTest extends TestCase
{
    #[Test]
    public function central_tables_exist_on_central_connection_only(): void
    {
        $this->assertTrue(Schema::connection('central')->hasTable('sso_authentication_transactions'));
        $this->assertTrue(Schema::connection('central')->hasTable('sso_tenant_handoffs'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('sso_authentication_transactions'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('sso_tenant_handoffs'));
    }

    #[Test]
    public function create_binds_idp_version_and_stores_hashes_not_plaintext_secrets(): void
    {
        $service = app(AuthenticationTransactionService::class);
        $versionId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();

        $created = $service->create([
            'tenant_id' => $tenantId,
            'destination_host' => 'acme.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'expected_issuer' => 'https://idp.example.com',
        ]);

        $txn = $created['transaction']->fresh();
        $this->assertSame($versionId, $txn->idp_configuration_version_id);
        $this->assertSame(SsoAuthenticationTransaction::STATUS_PENDING, $txn->status);
        $this->assertStringContainsString('.', $created['state']);
        $this->assertNotSame($created['state'], $txn->getAttributes()['state_secret_hash']);
        $this->assertTrue(SsoSecretCrypto::proofsMatch(
            (string) $txn->getAttributes()['state_secret_hash'],
            explode('.', $created['state'], 2)[1],
        ));
        $this->assertArrayNotHasKey('authorization_code', $txn->getAttributes());
    }

    #[Test]
    public function reserve_issue_and_consume_are_one_time_cas(): void
    {
        $service = app(AuthenticationTransactionService::class);
        $tenantId = (string) Str::uuid();
        $created = $service->create([
            'tenant_id' => $tenantId,
            'destination_host' => 'acme.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/app',
            'idp_configuration_version_id' => (string) Str::uuid(),
        ]);

        $authSecret = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $txn = $service->attachAuthBinding($created['transaction'], $authSecret);
        $this->assertSame(SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK, $txn->status);

        $found = $service->findByState($created['state']);
        $this->assertNotNull($found);
        $this->assertSame($txn->id, $found->id);

        $reserved = $service->reserveCallback($txn->id);
        $this->assertNotNull($reserved);
        $this->assertNull($service->reserveCallback($txn->id), 'Second reservation must fail closed');

        $issued = $service->issueHandoff($reserved, [
            'user_id' => (string) Str::uuid(),
            'assurance_evidence' => ['acr' => 'urn:example:aal1'],
        ]);
        $this->assertSame(SsoTenantHandoff::STATUS_ISSUED, $issued['handoff']->status);
        $this->assertNotNull($reserved->fresh()->secrets_erased_at);
        $this->assertSame('', $reserved->fresh()->getAttributes()['nonce_encrypted']);

        $consumed = $service->consumeHandoff(
            $issued['reference'],
            $tenantId,
            'acme.jabal.test',
            $created['tenant_continuation_secret'],
        );
        $this->assertNotNull($consumed);
        $this->assertSame(SsoTenantHandoff::STATUS_CONSUMED, $consumed->status);

        $this->assertNull($service->consumeHandoff(
            $issued['reference'],
            $tenantId,
            'acme.jabal.test',
            $created['tenant_continuation_secret'],
        ), 'Handoff replay must fail closed');
    }

    #[Test]
    public function consume_rejects_cross_tenant_or_wrong_continuation(): void
    {
        $service = app(AuthenticationTransactionService::class);
        $tenantId = (string) Str::uuid();
        $created = $service->create([
            'tenant_id' => $tenantId,
            'destination_host' => 'acme.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/app',
            'idp_configuration_version_id' => (string) Str::uuid(),
        ]);
        $txn = $service->attachAuthBinding(
            $created['transaction'],
            SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES),
        );
        $reserved = $service->reserveCallback($txn->id);
        $issued = $service->issueHandoff($reserved);

        $this->assertNull($service->consumeHandoff(
            $issued['reference'],
            (string) Str::uuid(),
            'acme.jabal.test',
            $created['tenant_continuation_secret'],
        ));

        $this->assertNull($service->consumeHandoff(
            $issued['reference'],
            $tenantId,
            'other.jabal.test',
            $created['tenant_continuation_secret'],
        ));

        $this->assertNull($service->consumeHandoff(
            $issued['reference'],
            $tenantId,
            'acme.jabal.test',
            'wrong-continuation-secret',
        ));
    }

    #[Test]
    public function concurrency_limit_supersedes_oldest_non_callback_transaction(): void
    {
        config(['identity.sso.auth_transaction_concurrency' => 3]);
        $service = app(AuthenticationTransactionService::class);
        $tenantId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $sharedContinuation = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $created = $service->create([
                'tenant_id' => $tenantId,
                'destination_host' => 'acme.jabal.test',
                'addressing_profile' => 'host',
                'post_login_path' => '/app',
                'idp_configuration_version_id' => $versionId,
                'tenant_continuation_secret' => $sharedContinuation,
            ]);
            $ids[] = $created['transaction']->id;
        }

        $pending = SsoAuthenticationTransaction::query()
            ->whereIn('id', $ids)
            ->where('status', SsoAuthenticationTransaction::STATUS_PENDING)
            ->count();
        $superseded = SsoAuthenticationTransaction::query()
            ->whereIn('id', $ids)
            ->where('status', SsoAuthenticationTransaction::STATUS_SUPERSEDED)
            ->where('failure_reason', 'concurrency_limit')
            ->count();
        $this->assertSame(3, $pending);
        $this->assertSame(1, $superseded);
    }
}
