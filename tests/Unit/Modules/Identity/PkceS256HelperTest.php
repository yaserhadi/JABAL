<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Support\Sso\PkceS256Helper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PkceS256HelperTest extends TestCase
{
    #[Test]
    public function generates_verifier_within_rfc7636_length_bounds(): void
    {
        $helper = new PkceS256Helper;
        $verifier = $helper->generateVerifier();

        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
    }

    #[Test]
    public function challenge_is_s256_base64url_of_verifier(): void
    {
        $helper = new PkceS256Helper;
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $helper->challengeForVerifier($verifier));
    }

    #[Test]
    public function generate_pair_returns_s256_method(): void
    {
        $helper = new PkceS256Helper;
        $pair = $helper->generatePair();

        $this->assertSame('S256', $pair['method']);
        $this->assertSame($helper->challengeForVerifier($pair['verifier']), $pair['challenge']);
    }
}
