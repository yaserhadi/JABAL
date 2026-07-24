<?php

namespace Tests\Unit\Modules\Identity;

use Facile\OpenIDClient\Token\TokenSetInterface;
use Mockery;
use Modules\Identity\Exceptions\SsoClaimsException;
use Modules\Identity\Support\Sso\SsoClaimsExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoClaimsExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function extracts_primary_id_token_claims(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'user-123',
            'email' => 'member@example.com',
            'email_verified' => true,
        ]);

        $claims = (new SsoClaimsExtractor)->extract($tokenSet);

        $this->assertSame('https://idp.example.com', $claims->issuer);
        $this->assertSame('user-123', $claims->subject);
        $this->assertTrue($claims->emailVerified);
    }

    #[Test]
    public function rejects_userinfo_sub_mismatch(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'user-123',
        ]);

        $this->expectException(SsoClaimsException::class);
        (new SsoClaimsExtractor)->extract($tokenSet, ['sub' => 'other-sub']);
    }

    #[Test]
    public function rejects_missing_id_token_sub(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn(['iss' => 'https://idp.example.com']);

        $this->expectException(SsoClaimsException::class);
        (new SsoClaimsExtractor)->extract($tokenSet);
    }

    #[Test]
    public function rejects_missing_id_token_iss(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn(['sub' => 'user-123']);

        $this->expectException(SsoClaimsException::class);
        (new SsoClaimsExtractor)->extract($tokenSet);
    }

    #[Test]
    public function uses_id_token_claims_only_not_access_token(): void
    {
        $source = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoClaimsExtractor.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('$tokenSet->claims()', $source);
        $this->assertStringNotContainsString('getAccessToken', $source);
        $this->assertStringNotContainsString('access_token', $source);
    }

    #[Test]
    public function accepts_userinfo_email_when_id_token_omits_email(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'user-123',
        ]);

        $claims = (new SsoClaimsExtractor)->extract($tokenSet, [
            'sub' => 'user-123',
            'email' => 'member@example.com',
            'email_verified' => true,
        ]);

        $this->assertSame('member@example.com', $claims->email);
        $this->assertTrue($claims->emailVerified);
    }
}
