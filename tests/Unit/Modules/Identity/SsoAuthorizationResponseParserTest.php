<?php

namespace Tests\Unit\Modules\Identity;

use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\SsoAuthorizationResponseParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 WS4 — Authorization Response parser (IH-7). */
class SsoAuthorizationResponseParserTest extends TestCase
{
    #[Test]
    public function query_mode_accepts_get_with_code_and_state(): void
    {
        $request = Request::create('/auth/enterprise-sso/callback?code=abc&state=lookup.secret', 'GET');
        $parsed = app(SsoAuthorizationResponseParser::class)->parse($request, 'query');

        $this->assertSame('abc', $parsed['code']);
        $this->assertSame('lookup.secret', $parsed['state']);
        $this->assertNull($parsed['error']);
    }

    #[Test]
    public function rejects_duplicate_query_parameters(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb?state=a&state=b&code=x', 'GET');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'query');
    }

    #[Test]
    public function rejects_code_and_error_together(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb?state=s&code=c&error=access_denied', 'GET');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'query');
    }

    #[Test]
    public function form_post_requires_urlencoded_and_rejects_json(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['state' => 's', 'code' => 'c']));
        app(SsoAuthorizationResponseParser::class)->parse($request, 'form_post');
    }

    #[Test]
    public function form_post_rejects_query_string_merge(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb?state=from-query', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'state=from-body&code=c');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'form_post');
    }

    #[Test]
    public function form_post_accepts_urlencoded_body(): void
    {
        $request = Request::create('/cb', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'state=lookup.secret&code=abc');
        $parsed = app(SsoAuthorizationResponseParser::class)->parse($request, 'form_post');

        $this->assertSame('abc', $parsed['code']);
        $this->assertSame('lookup.secret', $parsed['state']);
    }

    #[Test]
    public function rejects_duplicate_form_post_body_parameters(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'state=a&state=b&code=x');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'form_post');
    }

    #[Test]
    public function query_mode_rejects_post(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb?state=s&code=c', 'POST');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'query');
    }

    #[Test]
    public function requires_state_for_error_responses(): void
    {
        $this->expectException(SsoSecurityException::class);
        $request = Request::create('/cb?error=access_denied', 'GET');
        app(SsoAuthorizationResponseParser::class)->parse($request, 'query');
    }
}
