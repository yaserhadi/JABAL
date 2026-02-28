<?php

namespace App\Support\Context;

use Illuminate\Support\Str;

class RequestContext
{
    private static ?self $instance = null;

    private ?string $requestId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    private ?string $url = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function setFromRequest(\Illuminate\Http\Request $request): void
    {
        $this->requestId = (string) Str::uuid();
        $this->ip = $request->ip();
        $this->userAgent = $request->userAgent();
        $this->url = $request->fullUrl();
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * Get request ID (alias).
     */
    public function getRequestId(): ?string
    {
        return $this->requestId();
    }

    /**
     * Get IP address (alias).
     */
    public function getIp(): ?string
    {
        return $this->ip();
    }

    /**
     * Get user agent (alias).
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent();
    }

    /**
     * Get URL (alias).
     */
    public function getUrl(): ?string
    {
        return $this->url();
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'url' => $this->url,
        ];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
