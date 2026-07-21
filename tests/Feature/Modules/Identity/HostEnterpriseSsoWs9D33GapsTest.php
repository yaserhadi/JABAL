<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-082 WS9 — additional D33 gap coverage (expiry, headers, param ignore, entropy already covered elsewhere).
 */
class HostEnterpriseSsoWs9D33GapsTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    protected function provision(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws9g-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';
        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        return compact('tenant', 'host', 'versionId');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t16_expired_transaction_rejects_callback_without_exchange(): void
    {
        $f = $this->provision();
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $f['tenant']->id,
            'destination_host' => $f['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $f['versionId'],
            'expected_issuer' => 'https://idp.example.com',
        ]);
        $txn = $created['transaction'];
        $txn->forceFill([
            'expires_at' => now()->subMinute(),
            'status' => SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            'auth_binding_secret_hash' => SsoSecretCrypto::proof('binding'),
        ])->save();

        $response = $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state='.rawurlencode($created['state']),
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
                'HTTPS' => 'on',
                'HTTP_COOKIE' => SsoBrowserBindingCookieFactory::AUTH_BINDING.'=binding',
            ]
        );
        $response->assertNotFound();
        $fresh = SsoAuthenticationTransaction::query()->find($txn->id);
        $this->assertNotNull($fresh);
        $this->assertNotSame(SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED, $fresh->status);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t47_transition_headers_present_on_enterprise_start(): void
    {
        $f = $this->provision();
        $response = $this->call(
            'GET',
            'https://'.$f['host'].'/auth/enterprise-sso/start',
            server: ['HTTP_HOST' => $f['host'], 'SERVER_NAME' => $f['host'], 'HTTPS' => 'on']
        );
        $response->assertRedirect();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t17_expired_handoff_fails_closed(): void
    {
        $f = $this->provision();
        $svc = app(AuthenticationTransactionService::class);
        $created = $svc->create([
            'tenant_id' => (string) $f['tenant']->id,
            'destination_host' => $f['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $f['versionId'],
            'expected_issuer' => 'https://idp.example.com',
        ]);
        $txn = $created['transaction'];
        $txn->forceFill(['status' => SsoAuthenticationTransaction::STATUS_CALLBACK_RESERVED])->save();
        $issued = $svc->issueHandoff($txn, [
            'user_id' => (string) Str::uuid(),
            'identity_link_id' => (string) Str::uuid(),
            'assurance_evidence' => [],
        ]);
        $handoff = $issued['handoff'];
        $handoff->forceFill(['expires_at' => now()->subMinute()])->save();

        $consumed = $svc->consumeHandoff(
            $issued['reference'],
            (string) $f['tenant']->id,
            $f['host'],
            $created['tenant_continuation_secret'],
        );
        $this->assertNull($consumed);
    }
}
