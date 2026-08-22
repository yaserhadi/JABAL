<?php

namespace Tests\Unit\Modules\Identity;

use Facile\OpenIDClient\Token\TokenSetInterface;
use Mockery;
use Modules\Identity\Exceptions\SsoClaimsException;
use Modules\Identity\Support\Sso\SsoApprovedEmailDomainPolicy;
use Modules\Identity\Support\Sso\SsoCanonicalEmail;
use Modules\Identity\Support\Sso\SsoClaimsExtractor;
use Modules\Identity\Support\Sso\SsoExternalUserIdentifierMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoExternalIdentityTrustUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function google_and_okta_map_stable_sub_to_euid(): void
    {
        $mapper = new SsoExternalUserIdentifierMapper;

        $google = $mapper->map([
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-stable-sub',
            'email' => 'user@example.com',
        ]);
        $this->assertSame('google-stable-sub', $google->euid);
        $this->assertSame(SsoExternalUserIdentifierMapper::FAMILY_GOOGLE, $google->providerFamily);

        $okta = $mapper->map([
            'iss' => 'https://example.okta.com/oauth2/default',
            'sub' => 'okta-stable-sub',
        ]);
        $this->assertSame('okta-stable-sub', $okta->euid);
        $this->assertSame(SsoExternalUserIdentifierMapper::FAMILY_OKTA, $okta->providerFamily);
    }

    #[Test]
    public function entra_maps_oid_not_sub_or_upn(): void
    {
        $mapper = new SsoExternalUserIdentifierMapper;
        $mapped = $mapper->map([
            'iss' => 'https://login.microsoftonline.com/tenant-guid/v2.0',
            'sub' => 'pairwise-sub-not-oid',
            'oid' => 'entra-object-id',
            'tid' => 'tenant-guid',
            'preferred_username' => 'user@contoso.com',
            'email' => 'user@contoso.com',
        ]);

        $this->assertSame('entra-object-id', $mapped->euid);
        $this->assertSame(SsoExternalUserIdentifierMapper::FAMILY_ENTRA, $mapped->providerFamily);
        $this->assertNotSame('pairwise-sub-not-oid', $mapped->euid);
    }

    #[Test]
    public function entra_without_oid_fails_closed(): void
    {
        $this->expectException(SsoClaimsException::class);
        (new SsoExternalUserIdentifierMapper)->map([
            'iss' => 'https://login.microsoftonline.com/tenant-guid/v2.0',
            'sub' => 'pairwise-sub-only',
        ]);
    }

    #[Test]
    public function email_shaped_identifier_is_rejected(): void
    {
        $this->expectException(SsoClaimsException::class);
        (new SsoExternalUserIdentifierMapper)->map([
            'iss' => 'https://idp.example.com',
            'sub' => 'user@example.com',
        ]);
    }

    #[Test]
    public function extractor_stores_entra_oid_as_subject(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://login.microsoftonline.com/aaa/v2.0',
            'sub' => 'pairwise',
            'oid' => 'object-id-1',
            'email' => 'owner@example.com',
            'email_verified' => true,
        ]);

        $claims = (new SsoClaimsExtractor)->extract($tokenSet);
        $this->assertSame('object-id-1', $claims->subject);
        $this->assertSame('entra', $claims->providerFamily);
        $this->assertSame('owner@example.com', $claims->email);
    }

    #[Test]
    public function canonical_email_case_normalization_does_not_rewrite_plus_tags(): void
    {
        $this->assertTrue(SsoCanonicalEmail::equals('User@Example.COM', 'user@example.com'));
        $this->assertFalse(SsoCanonicalEmail::equals('user+tag@example.com', 'user@example.com'));
        $this->assertSame('example.com', SsoCanonicalEmail::domain('User@Example.COM'));
    }

    #[Test]
    public function empty_approved_domains_fail_closed_and_domain_is_not_user_discovery(): void
    {
        $this->assertFalse(SsoApprovedEmailDomainPolicy::allows('user@example.com', []));
        $this->assertTrue(SsoApprovedEmailDomainPolicy::allows('user@example.com', ['example.com']));
        $this->assertFalse(SsoApprovedEmailDomainPolicy::allows('user@other.com', ['example.com']));
        $this->assertSame(['example.com', 'contoso.com'], SsoApprovedEmailDomainPolicy::normalizeList([
            '@Example.COM',
            'contoso.com.',
            'contoso.com',
        ]));
    }

    #[Test]
    public function entra_oid_may_come_from_userinfo_when_absent_from_id_token(): void
    {
        $mapped = (new SsoExternalUserIdentifierMapper)->map(
            [
                'iss' => 'https://login.microsoftonline.com/tenant-guid/v2.0',
                'sub' => 'pairwise-sub',
            ],
            [
                'oid' => 'entra-oid-from-userinfo',
            ],
        );

        $this->assertSame('entra-oid-from-userinfo', $mapped->euid);
    }
}
