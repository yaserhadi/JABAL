<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-008 schema-models — tenant SSO tables and models only. */
class TenantSsoSchemaTest extends TestCase
{
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreSharedTenantConnectionDatabase();
    }

    protected function tearDown(): void
    {
        $this->restoreSharedTenantConnectionDatabase();
        parent::tearDown();
    }

    public function test_tenant_migrations_create_sso_tables(): void
    {
        $this->assertTrue(Schema::connection('tenant')->hasTable('tenant_sso_config'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('tenant_user_identities'));
    }

    public function test_tenant_sso_config_enforces_one_row_per_tenant(): void
    {
        $user = $this->registerTenantUser('SSO Config', 'sso-cfg-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        tenancy()->initialize($tenant);
        try {
            TenantSsoConfig::query()->create([
                'tenant_id' => $tenant->id,
                'enabled' => false,
            ]);

            $this->expectException(QueryException::class);
            TenantSsoConfig::query()->create([
                'tenant_id' => $tenant->id,
                'enabled' => true,
            ]);
        } finally {
            tenancy()->end();
        }
    }

    public function test_tenant_sso_config_hides_encrypted_secret_from_serialization(): void
    {
        $user = $this->registerTenantUser('SSO Secret', 'sso-secret-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        tenancy()->initialize($tenant);
        try {
            $config = TenantSsoConfig::query()->create([
                'tenant_id' => $tenant->id,
                'client_secret_encrypted' => 'encrypted-blob-not-for-api',
            ]);

            $array = $config->fresh()->toArray();
            $this->assertArrayNotHasKey('client_secret_encrypted', $array);
        } finally {
            tenancy()->end();
        }
    }

    public function test_tenant_user_identity_unique_on_tenant_issuer_subject(): void
    {
        $user = $this->registerTenantUser('SSO User', 'sso-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $issuer = 'https://idp.example.com';
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        try {
            TenantUserIdentity::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'issuer' => $issuer,
                'subject' => $subject,
                'email_at_link' => $user->email,
            ]);

            $this->expectException(QueryException::class);
            TenantUserIdentity::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'issuer' => $issuer,
                'subject' => $subject,
                'email_at_link' => 'other@example.com',
            ]);
        } finally {
            tenancy()->end();
        }
    }

    public function test_dedicated_storage_sso_tables_exist_on_physical_database(): void
    {
        $this->setUpDedicatedTenantMode();
        $this->restoreSharedTenantConnectionDatabase();
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');

        $email = 'ded-sso-schema-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        tenancy()->initialize($tenant);
        try {
            TenantSsoConfig::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['enabled' => true, 'provider_label' => 'Test IdP']
            );

            $identity = TenantUserIdentity::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'issuer' => 'https://dedicated-idp.example.com',
                'subject' => 'ded-sub-'.Str::uuid()->toString(),
            ]);
        } finally {
            tenancy()->end();
        }

        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $sharedDb = DB::connection($sharedConnection)->selectOne('select current_database() as db');
        $dedicatedDb = DB::connection($connection)->selectOne('select current_database() as db');
        $this->assertNotSame(
            $sharedDb->db,
            $dedicatedDb->db,
            'Shared and dedicated testing databases must be physically separate. Run: php tests/Support/ensure_dedicated_test_databases.php'
        );

        $this->assertTableRowOnDedicatedNotShared('tenant_sso_config', [
            'tenant_id' => $tenant->id,
        ], $connection);

        $this->assertTableRowOnDedicatedNotShared('tenant_user_identities', [
            'id' => $identity->id,
        ], $connection);
    }
}
