<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\SsoIssuerUrlValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoIssuerUrlValidatorTest extends TestCase
{
    #[Test]
    public function rejects_non_https_issuer(): void
    {
        $validator = new SsoIssuerUrlValidator;

        $this->expectException(SsoSecurityException::class);
        $validator->validateConfiguredIssuer('http://login.example.com');
    }

    #[Test]
    public function rejects_localhost_issuer(): void
    {
        $validator = new SsoIssuerUrlValidator;

        $this->expectException(SsoSecurityException::class);
        $validator->validateConfiguredIssuer('https://localhost/oauth');
    }

    #[Test]
    public function rejects_private_ip_literal_issuer(): void
    {
        $validator = new SsoIssuerUrlValidator;

        $this->expectException(SsoSecurityException::class);
        $validator->validateConfiguredIssuer('https://192.168.1.10/oidc');
    }

    #[Test]
    public function rejects_discovered_issuer_mismatch(): void
    {
        $validator = new SsoIssuerUrlValidator;

        $this->expectException(SsoSecurityException::class);
        $validator->assertDiscoveredIssuerMatches(
            'https://idp.example.com',
            'https://evil.example.com'
        );
    }

    #[Test]
    public function accepts_public_https_issuer_and_normalizes_trailing_slash(): void
    {
        $validator = new SsoIssuerUrlValidator;

        $normalized = $validator->validateConfiguredIssuer('https://login.microsoftonline.com/tenant-id/v2.0/');

        $this->assertSame('https://login.microsoftonline.com/tenant-id/v2.0', $normalized);
    }
}
