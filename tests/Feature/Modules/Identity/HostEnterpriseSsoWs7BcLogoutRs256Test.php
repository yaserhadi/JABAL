<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBackChannelLogoutTokenValidator;
use Modules\Identity\Support\Sso\SsoRsaJwk;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-082 WS7 corrective — RS256/JWKS Back-Channel Logout protocol fixtures (T43/T44 asymmetric).
 */
class HostEnterpriseSsoWs7BcLogoutRs256Test extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected string $issuer = 'https://idp.example.com';

    protected string $jwksUri = 'https://idp.example.com/oauth2/v2.0/keys';

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * Single Http::fake registration — Laravel merges fakes; do not stack stubs.
     *
     * @param  list<array<string, mixed>>|callable  $jwksKeysOrResponder
     */
    protected function fakeTrustedIdpHttp(array|callable $jwksKeysOrResponder): void
    {
        Http::fake(function ($request) use ($jwksKeysOrResponder) {
            if ($request->url() === $this->jwksUri) {
                if (is_callable($jwksKeysOrResponder)) {
                    return $jwksKeysOrResponder($request);
                }

                return Http::response(['keys' => $jwksKeysOrResponder], 200);
            }

            if ($request->url() === $this->issuer.'/.well-known/openid-configuration') {
                return Http::response([
                    'issuer' => $this->issuer,
                    'jwks_uri' => $this->jwksUri,
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
    }

    /**
     * @return array{tenant: Tenant, user: User, versionId: string, privatePem: string, jwk: array<string, mixed>}
     */
    protected function prepareRs256Tenant(): array
    {
        $pair = SsoRsaJwk::loadFixtureKeyPair('primary');

        $tenant = Tenant::factory()->create([
            'slug' => 'ws7r-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS7 RS User',
            'email' => 'ws7r-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => $this->issuer,
            'client_id' => 'client-id',
            'client_secret' => 'unused-for-rs256',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
            'jwks_uri' => $this->jwksUri,
            'logout_token_signing_algs' => ['RS256'],
        ]);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'subject-ws7r',
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'versionId' => $versionId,
            'privatePem' => $pair['private_pem'],
            'jwk' => $pair['jwk'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function mintLogoutToken(string $privatePem, string $kid, array $overrides = []): string
    {
        $validator = app(SsoBackChannelLogoutTokenValidator::class);
        $payload = array_merge([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'jti-'.Str::uuid()->toString(),
            'sid' => 'provider-sid-rs',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], $overrides);

        return $validator->mintRs256ForTests($payload, $privatePem, ['kid' => $kid]);
    }

    protected function postBackchannel(string $tenantId, string $token)
    {
        return $this->call(
            'POST',
            'https://auth.jabal.test/auth/enterprise-sso/backchannel-logout?tenant='.$tenantId,
            ['logout_token' => $token],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function valid_rs256_logout_token_revokes_scoped_session(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'sess-rs-1',
            'idp_sid' => 'provider-sid-rs',
            'idp_issuer' => $this->issuer,
            'idp_configuration_version_id' => $fixture['versionId'],
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        $token = $this->mintLogoutToken($fixture['privatePem'], 'kid-primary');
        $this->postBackchannel((string) $fixture['tenant']->id, $token)->assertOk();

        tenancy()->initialize($fixture['tenant']);
        $this->assertNotNull(UserSession::query()->where('idp_sid', 'provider-sid-rs')->value('revoked_at'));
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function invalid_rs256_signature_causes_no_session_change(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);
        $other = SsoRsaJwk::loadFixtureKeyPair('rotated');

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'sess-rs-2',
            'idp_sid' => 'provider-sid-rs',
            'idp_issuer' => $this->issuer,
            'idp_configuration_version_id' => $fixture['versionId'],
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        $token = $this->mintLogoutToken($other['private_pem'], 'kid-primary');
        $this->postBackchannel((string) $fixture['tenant']->id, $token)->assertStatus(400);

        tenancy()->initialize($fixture['tenant']);
        $this->assertNull(UserSession::query()->where('idp_sid', 'provider-sid-rs')->value('revoked_at'));
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function wrong_issuer_or_audience_rejected(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);

        $badIssuer = $this->mintLogoutToken($fixture['privatePem'], 'kid-primary', [
            'iss' => 'https://evil.example.com',
        ]);
        $this->postBackchannel((string) $fixture['tenant']->id, $badIssuer)->assertStatus(400);

        $badAud = $this->mintLogoutToken($fixture['privatePem'], 'kid-primary', [
            'aud' => 'wrong-client',
        ]);
        $this->postBackchannel((string) $fixture['tenant']->id, $badAud)->assertStatus(400);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function alg_none_and_hs256_without_config_are_rejected(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);
        $validator = app(SsoBackChannelLogoutTokenValidator::class);

        $noneHeader = SsoRsaJwk::b64urlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = SsoRsaJwk::b64urlEncode(json_encode([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'jti' => 'jti-none',
            'sid' => 'x',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], JSON_THROW_ON_ERROR));
        // Non-empty signature segment so decode succeeds and alg allowlist rejects `none`.
        $this->postBackchannel((string) $fixture['tenant']->id, $noneHeader.'.'.$body.'.e30')->assertStatus(400);

        $hs = $validator->mintHmacSha256ForTests([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'jti' => 'jti-hs',
            'sid' => 'x',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], 'client-secret-value-for-hs256');
        // RS256-only config must not accept HS256 (no automatic fallback).
        $this->postBackchannel((string) $fixture['tenant']->id, $hs)->assertStatus(400);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function unknown_kid_refreshes_once_then_rotation_succeeds(): void
    {
        $rotated = SsoRsaJwk::loadFixtureKeyPair('rotated');
        $fixture = $this->prepareRs256Tenant();

        $call = 0;
        $this->fakeTrustedIdpHttp(function () use (&$call, $fixture, $rotated) {
            $call++;
            $keys = $call === 1
                ? [$fixture['jwk']]
                : [$fixture['jwk'], $rotated['jwk']];

            return Http::response(['keys' => $keys], 200);
        });

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'sess-rot',
            'idp_sid' => 'provider-sid-rs',
            'idp_issuer' => $this->issuer,
            'idp_configuration_version_id' => $fixture['versionId'],
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        // Prime cache with primary-only set.
        $this->postBackchannel(
            (string) $fixture['tenant']->id,
            $this->mintLogoutToken($fixture['privatePem'], 'kid-primary', ['jti' => 'jti-prime-'.uniqid(), 'sid' => 'other-sid'])
        )->assertOk();

        $token = $this->mintLogoutToken($rotated['private_pem'], 'kid-rotated', [
            'jti' => 'jti-rot-'.uniqid(),
            'sid' => 'provider-sid-rs',
        ]);
        $this->postBackchannel((string) $fixture['tenant']->id, $token)->assertOk();
        $this->assertGreaterThanOrEqual(2, $call);

        tenancy()->initialize($fixture['tenant']);
        $this->assertNotNull(UserSession::query()->where('session_id', 'sess-rot')->value('revoked_at'));
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function unresolved_kid_fails_closed_after_one_refresh(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);
        $orphan = SsoRsaJwk::loadFixtureKeyPair('rotated');

        $token = $this->mintLogoutToken($orphan['private_pem'], 'kid-never-published');
        $this->postBackchannel((string) $fixture['tenant']->id, $token)->assertStatus(400);

        $jwksCalls = Http::recorded(fn ($request) => $request->url() === $this->jwksUri)->count();
        $this->assertSame(2, $jwksCalls); // initial JWKS + one refresh, then fail-closed
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function algorithm_confusion_hs256_material_rejected_on_rs256_path(): void
    {
        $fixture = $this->prepareRs256Tenant();
        $this->fakeTrustedIdpHttp([$fixture['jwk']]);

        // Token advertises RS256 + published kid, but signature is HMAC (confusion attempt).
        $header = SsoRsaJwk::b64urlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'kid-primary',
        ], JSON_THROW_ON_ERROR));
        $body = SsoRsaJwk::b64urlEncode(json_encode([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'jti-conf-'.uniqid(),
            'sid' => 'provider-sid-rs',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$body;
        $sig = SsoRsaJwk::b64urlEncode(hash_hmac('sha256', $signingInput, 'client-secret-value-for-hs256', true));
        $this->postBackchannel((string) $fixture['tenant']->id, $signingInput.'.'.$sig)->assertStatus(400);
    }
}
