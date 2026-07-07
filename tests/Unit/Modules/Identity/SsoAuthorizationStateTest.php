<?php

namespace Tests\Unit\Modules\Identity;

use Illuminate\Support\Facades\Crypt;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoAuthorizationStateTest extends TestCase
{
    #[Test]
    public function encodes_and_parses_tenant_bound_state(): void
    {
        $payload = SsoAuthorizationState::mint('11111111-1111-1111-1111-111111111111');
        $encoded = SsoAuthorizationState::encode($payload);

        $parsed = SsoAuthorizationState::parse($encoded);

        $this->assertSame($payload['tenant_id'], $parsed['tenant_id']);
        $this->assertSame($payload['csrf'], $parsed['csrf']);
    }

    #[Test]
    public function rejects_tampered_state(): void
    {
        $this->expectException(SsoSecurityException::class);
        SsoAuthorizationState::parse('not-valid-encrypted-state');
    }

    #[Test]
    public function rejects_expired_state(): void
    {
        $encoded = Crypt::encryptString(json_encode([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'csrf' => 'csrf-token',
            'exp' => now()->subMinute()->timestamp,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(SsoSecurityException::class);
        SsoAuthorizationState::parse($encoded);
    }
}
