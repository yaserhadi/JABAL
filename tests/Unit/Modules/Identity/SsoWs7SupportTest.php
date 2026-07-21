<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Support\Sso\SsoBackChannelLogoutTokenValidator;
use Modules\Identity\Support\Sso\SsoObservabilityRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 WS7 — IH-5 redaction + BC logout token claim validation. */
class SsoWs7SupportTest extends TestCase
{
    #[Test]
    public function observability_redactor_scrubs_prohibited_keys_and_jwts(): void
    {
        $redacted = SsoObservabilityRedactor::redact([
            'tenant_id' => 't-1',
            'authorization_code' => 'secret-code',
            'nested' => ['access_token' => 'tok', 'ok' => 1],
            'jwt' => 'aaa.bbb.ccc',
        ]);

        $this->assertSame('t-1', $redacted['tenant_id']);
        $this->assertSame('[redacted]', $redacted['authorization_code']);
        $this->assertSame('[redacted]', $redacted['nested']['access_token']);
        $this->assertSame(1, $redacted['nested']['ok']);
        $this->assertSame('[redacted]', $redacted['jwt']);
        $this->assertStringContainsString('[redacted]', SsoObservabilityRedactor::redactString('Bearer abcdefghijklmnopqrstuvwxyz012345'));
    }

    #[Test]
    public function logout_token_validator_accepts_valid_hs256_claims(): void
    {
        $validator = new SsoBackChannelLogoutTokenValidator;
        $secret = 'test-client-secret-value';
        $payload = [
            'iss' => 'https://idp.example.com',
            'aud' => 'client-id',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'jti-'.uniqid(),
            'sub' => 'subject-1',
            'sid' => 'sid-1',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ];
        $jwt = $validator->mintHmacSha256ForTests($payload, $secret);
        $decoded = $validator->decode($jwt);
        $this->assertNotNull($decoded);
        $this->assertNull($validator->validateClaims(
            $decoded['payload'],
            'https://idp.example.com',
            'client-id',
        ));
        $this->assertTrue($validator->verifyHmacSha256($decoded['signing_input'], $decoded['signature'], $secret));
    }

    #[Test]
    public function logout_token_validator_rejects_nonce_and_bad_audience(): void
    {
        $validator = new SsoBackChannelLogoutTokenValidator;
        $this->assertSame('nonce_present', $validator->validateClaims([
            'iss' => 'https://idp.example.com',
            'aud' => 'client-id',
            'iat' => time(),
            'jti' => 'jti-1',
            'sub' => 's',
            'nonce' => 'bad',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => []],
        ], 'https://idp.example.com', 'client-id'));

        $this->assertSame('audience_mismatch', $validator->validateClaims([
            'iss' => 'https://idp.example.com',
            'aud' => 'other-client',
            'iat' => time(),
            'jti' => 'jti-2',
            'sub' => 's',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => []],
        ], 'https://idp.example.com', 'client-id'));
    }
}
