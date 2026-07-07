<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Identity\Support\Sso\LaravelSessionAuthSessionAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaravelSessionAuthSessionAdapterTest extends TestCase
{
    #[Test]
    public function stores_and_pulls_state_nonce_and_code_verifier(): void
    {
        $tenantId = (string) Str::uuid();
        $session = $this->app['session.store'];

        $adapter = new LaravelSessionAuthSessionAdapter($session, $tenantId);
        $adapter->initializeForAuthorization('pkce-verifier-value');

        $this->assertNotNull($adapter->getState());
        $this->assertNotNull($adapter->getNonce());
        $this->assertSame('pkce-verifier-value', $adapter->getCodeVerifier());
        $this->assertSame($tenantId, $adapter->getCustoms()['tenant_id'] ?? null);

        $loaded = LaravelSessionAuthSessionAdapter::pull($session, $tenantId);

        $this->assertNotNull($loaded);
        $this->assertSame($adapter->getState(), $loaded->getState());
        $this->assertSame($adapter->getNonce(), $loaded->getNonce());
        $this->assertSame('pkce-verifier-value', $loaded->getCodeVerifier());
        $this->assertNull(LaravelSessionAuthSessionAdapter::load($session, $tenantId));
    }
}
