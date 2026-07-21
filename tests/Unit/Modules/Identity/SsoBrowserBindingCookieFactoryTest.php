<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 WS3 — host-only binding cookie attributes (IH-3). */
class SsoBrowserBindingCookieFactoryTest extends TestCase
{
    #[Test]
    public function tenant_continuation_cookie_is_host_only_lax_httponly(): void
    {
        $cookie = SsoBrowserBindingCookieFactory::make(
            SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
            'secret-value',
            600,
            true,
        );

        $this->assertSame(SsoBrowserBindingCookieFactory::TENANT_CONTINUATION, $cookie->getName());
        $this->assertSame('secret-value', $cookie->getValue());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    #[Test]
    public function auth_binding_cookie_is_host_only_lax_httponly(): void
    {
        $cookie = SsoBrowserBindingCookieFactory::make(
            SsoBrowserBindingCookieFactory::AUTH_BINDING,
            'binding-secret',
            600,
            true,
        );

        $this->assertSame(SsoBrowserBindingCookieFactory::AUTH_BINDING, $cookie->getName());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }
}
