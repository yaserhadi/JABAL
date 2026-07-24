<?php

namespace Tests\Unit\Modules\Identity\Support\Sso\Credentials;

use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecretReferenceTest extends TestCase
{
    #[Test]
    public function accepts_opaque_logical_keys(): void
    {
        $ref = new SecretReference(
            'local_sealed',
            'enterprise-sso/tenant-a/version-b/client-secret',
            'oidc_client_secret',
            'current',
            'local',
            'active',
        );

        $this->assertSame('local_sealed', $ref->provider);
        $this->assertSame('enterprise-sso/tenant-a/version-b/client-secret', $ref->reference);
    }

    #[Test]
    #[DataProvider('filesystemLikeReferences')]
    public function rejects_filesystem_like_references(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretReference('local_sealed', $bad, 'oidc_client_secret');
    }

    /** @return array<string, array{0: string}> */
    public static function filesystemLikeReferences(): array
    {
        return [
            'absolute_unix' => ['/var/secrets/foo'],
            'absolute_win' => ['C:\\secrets\\foo'],
            'traversal' => ['enterprise-sso/../other/secret'],
            'backslash_root' => ['\\secrets\\foo'],
        ];
    }

    #[Test]
    public function resolution_result_redacts_debug_info(): void
    {
        $ok = SecretResolutionResult::success('super-secret');
        $this->assertTrue($ok->ok);
        $this->assertSame('super-secret', $ok->consumeValue());
        $this->assertSame('[redacted]', $ok->__debugInfo()['value']);

        $fail = SecretResolutionResult::failure('missing');
        $this->assertFalse($fail->ok);
        $this->assertNull($fail->consumeValue());
    }
}
