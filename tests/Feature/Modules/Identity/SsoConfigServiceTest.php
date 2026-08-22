<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Services\SsoConfigService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoConfigServiceTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function get_for_tenant_never_exposes_client_secret(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'super-secret',
        ]);

        $payload = app(SsoConfigService::class)->getForTenant($tenant);
        tenancy()->end();

        $this->assertTrue($payload['has_client_secret']);
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertArrayNotHasKey('client_secret_encrypted', $payload);
    }

    #[Test]
    public function update_provisions_reference_credential_write_only(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $record = app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'write-only-secret',
        ]);

        $this->assertTrue($record->enabled);
        $this->assertArrayNotHasKey('client_secret_encrypted', $record->getAttributes());
        $this->assertSame('write-only-secret', app(SsoConfigService::class)->resolveClientSecretForTenant($tenant));
        $public = app(SsoConfigService::class)->getForTenant($tenant);
        tenancy()->end();

        $this->assertTrue($public['has_client_secret']);
        $this->assertArrayNotHasKey('client_secret', $public);
    }

    #[Test]
    public function is_operational_requires_org_entitlement_and_enabled_config(): void
    {
        $tenant = Tenant::factory()->create();
        $service = app(SsoConfigService::class);

        $this->assertFalse($service->isOperationalForTenant($tenant));

        $this->grantSsoAvailable($tenant);
        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $this->assertTrue($service->isOperationalForTenant($tenant));
    }

    #[Test]
    public function disable_for_entitlement_loss_preserves_secrets_and_disables_login(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'preserved-secret',
        ]);
        tenancy()->end();

        $service = app(SsoConfigService::class);
        $this->assertTrue($service->disableForEntitlementLoss($tenant));
        $this->assertFalse($service->isOperationalForTenant($tenant));
        $this->assertSame('preserved-secret', $service->resolveClientSecretForTenant($tenant));

        $public = $service->getForTenant($tenant);
        $this->assertFalse($public['enabled']);
        $this->assertTrue($public['disabled_by_entitlement']);
        $this->assertTrue($public['has_client_secret']);
    }

    #[Test]
    public function clear_entitlement_disable_flag_does_not_auto_enable_sso(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => false,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        TenantSsoConfig::query()->where('tenant_id', $tenant->id)->update([
            'disabled_by_entitlement' => true,
        ]);
        tenancy()->end();

        $service = app(SsoConfigService::class);
        $this->assertTrue($service->clearEntitlementDisableFlag($tenant));

        $public = $service->getForTenant($tenant);
        $this->assertFalse($public['enabled']);
        $this->assertFalse($public['disabled_by_entitlement']);
        $this->assertFalse($service->isOperationalForTenant($tenant));
    }

    #[Test]
    public function config_audit_snapshot_excludes_raw_secrets(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $logged = [];

        $this->app->bind(\App\Support\Contracts\Audit\AuditLoggerInterface::class, function () use (&$logged) {
            return new class($logged) implements \App\Support\Contracts\Audit\AuditLoggerInterface
            {
                public function __construct(private array &$logged) {}

                public function log(string $event, array $context = []): void
                {
                    $this->logged[] = ['event' => $event, 'context' => $context];
                }

                public function logCreated(object $model): void {}

                public function logUpdated(object $model, array $oldValues, array $newValues): void {}

                public function logDeleted(object $model): void {}
            };
        });

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'audit-secret-never-logged',
        ]);
        tenancy()->end();

        $this->assertNotEmpty($logged);
        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('audit-secret-never-logged', $encoded);
        $this->assertStringNotContainsString('client_secret_encrypted', $encoded);
        $this->assertStringContainsString('has_client_secret', $encoded);
    }

    #[Test]
    public function approved_email_domains_are_normalized_and_copied_to_active_version(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'approved_email_domains' => ['@Example.COM', 'contoso.com.', 'example.com'],
        ]);

        $public = app(SsoConfigService::class)->getForTenant($tenant);
        $this->assertSame(['example.com', 'contoso.com'], $public['approved_email_domains']);

        $version = app(SsoConfigService::class)->getActiveVersion($tenant);
        $this->assertSame(['example.com', 'contoso.com'], $version?->approved_email_domains);
        tenancy()->end();
    }
}
