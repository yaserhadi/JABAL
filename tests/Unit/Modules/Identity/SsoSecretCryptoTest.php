<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Support\Sso\SsoSecretCrypto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 IH-1 / IH-2 crypto floors. */
class SsoSecretCryptoTest extends TestCase
{
    #[Test]
    public function opaque_tokens_meet_byte_floors_and_proofs_use_constant_time_compare(): void
    {
        $state = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::STATE_SECRET_BYTES);
        $handoff = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::HANDOFF_SECRET_BYTES);
        $verifier = SsoSecretCrypto::pkceCodeVerifier();

        $this->assertGreaterThanOrEqual(43, strlen($state));
        $this->assertGreaterThanOrEqual(43, strlen($handoff));
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));

        $proof = SsoSecretCrypto::proof($state);
        $this->assertTrue(SsoSecretCrypto::proofsMatch($proof, $state));
        $this->assertFalse(SsoSecretCrypto::proofsMatch($proof, $handoff));
        $this->assertTrue(SsoSecretCrypto::proofsEqual($proof, SsoSecretCrypto::proof($state)));
    }

    #[Test]
    public function pkce_challenge_is_s256_base64url(): void
    {
        $verifier = SsoSecretCrypto::pkceCodeVerifier();
        $challenge = SsoSecretCrypto::pkceChallengeS256($verifier);
        $this->assertNotSame($verifier, $challenge);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $challenge);
    }
}
