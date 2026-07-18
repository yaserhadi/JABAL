<?php

declare(strict_types=1);

/**
 * BK-073 / DEC-0023 — Tenant addressing profiles and canonical origin.
 *
 * Valid profiles: host | path only. Profile C (host_redirect) is rejected at boot (BK-096).
 * Services MUST read config('tenancy_addressing.*') — never env() directly.
 */
$centralHostsRaw = (string) env('TENANCY_CENTRAL_HOSTS', 'localhost,127.0.0.1');
$centralHosts = array_values(array_filter(array_map(
    static fn (string $h): string => strtolower(trim($h)),
    explode(',', $centralHostsRaw)
)));

$platformBaseDomain = strtolower(trim((string) env('TENANT_PLATFORM_BASE_DOMAIN', '')));
$platformHost = strtolower(trim((string) env('TENANCY_PLATFORM_HOST', '')));
$authHost = strtolower(trim((string) env('TENANCY_AUTH_HOST', '')));
$apiHost = strtolower(trim((string) env('TENANCY_API_HOST', '')));
$assetHost = strtolower(trim((string) env('TENANCY_ASSET_HOST', '')));
$operationsHost = strtolower(trim((string) env('TENANCY_OPERATIONS_HOST', '')));

$canonicalPort = env('TENANCY_CANONICAL_PORT');
$canonicalPort = ($canonicalPort === null || $canonicalPort === '')
    ? null
    : (int) $canonicalPort;

$trustedProxiesRaw = (string) env('TENANCY_TRUSTED_PROXIES', '');
$trustedProxies = array_values(array_filter(array_map(
    static fn (string $p): string => trim($p),
    explode(',', $trustedProxiesRaw)
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment addressing profile
    |--------------------------------------------------------------------------
    |
    | host — Platform subdomain Tenant Hosts (DEC-0023 Profile A, default)
    | path — /t/{handle|uuid} (DEC-0023 Profile B)
    |
    | host_redirect (Profile C) is NOT supported in BK-073 — boot fails (BK-096).
    |
    */
    'profile' => strtolower(trim((string) env('TENANCY_ADDRESSING_PROFILE', 'path'))),

    'platform_base_domain' => $platformBaseDomain,

    'platform_host' => $platformHost,

    'auth_host' => $authHost,

    'api_host' => $apiHost,

    'asset_host' => $assetHost,

    'operations_host' => $operationsHost,

    'central_hosts' => $centralHosts,

    /*
    |--------------------------------------------------------------------------
    | Canonical origin (URL generation — NEVER from request Host)
    |--------------------------------------------------------------------------
    */
    'canonical_scheme' => strtolower(trim((string) env(
        'TENANCY_CANONICAL_SCHEME',
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_SCHEME) ?: 'http'
    ))),

    'canonical_port' => $canonicalPort,

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies baseline (no "*")
    |--------------------------------------------------------------------------
    */
    'trusted_proxies' => $trustedProxies,

    'trust_forwarded_headers' => (bool) env('TENANCY_TRUST_FORWARDED_HEADERS', false),

    /*
    |--------------------------------------------------------------------------
    | Domain metadata contract
    |--------------------------------------------------------------------------
    */
    'domain_category_platform_subdomain' => 'platform_subdomain',

];
