<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only accessor for the active Tenant addressing profile (BK-073 / DEC-0023).
 *
 * Profile is deployment-wide. No per-Tenant switching. No host_redirect support.
 */
final class TenantAddressingProfile
{
    public const PROFILE_HOST = 'host';

    public const PROFILE_PATH = 'path';

    /** @var list<string> */
    public const VALID_PROFILES = [self::PROFILE_HOST, self::PROFILE_PATH];

    public function profile(): string
    {
        return (string) config('tenancy_addressing.profile', self::PROFILE_PATH);
    }

    public function isHost(): bool
    {
        return $this->profile() === self::PROFILE_HOST;
    }

    public function isPath(): bool
    {
        return $this->profile() === self::PROFILE_PATH;
    }

    public function platformBaseDomain(): string
    {
        return (string) config('tenancy_addressing.platform_base_domain', '');
    }

    public function platformHost(): string
    {
        $configured = (string) config('tenancy_addressing.platform_host', '');
        if ($configured !== '') {
            return $configured;
        }

        $appUrl = (string) config('app.url', '');
        $host = parse_url($appUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    public function authHost(): string
    {
        $configured = (string) config('tenancy_addressing.auth_host', '');

        return $configured !== '' ? $configured : $this->platformHost();
    }

    public function apiHost(): string
    {
        return (string) config('tenancy_addressing.api_host', '');
    }

    public function assetHost(): string
    {
        return (string) config('tenancy_addressing.asset_host', '');
    }

    public function operationsHost(): string
    {
        return (string) config('tenancy_addressing.operations_host', '');
    }

    /**
     * @return list<string>
     */
    public function centralHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = array_values(array_unique(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            (array) config('tenancy_addressing.central_hosts', [])
        ))));

        foreach ([
            $this->platformHost(),
            $this->authHost(),
            $this->apiHost(),
            $this->assetHost(),
            $this->operationsHost(),
            $this->platformBaseDomain(),
        ] as $extra) {
            if ($extra !== '' && ! in_array($extra, $hosts, true)) {
                $hosts[] = $extra;
            }
        }

        return $hosts;
    }

    public function canonicalScheme(): string
    {
        $scheme = strtolower((string) config('tenancy_addressing.canonical_scheme', 'https'));

        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    public function canonicalPort(): ?int
    {
        $port = config('tenancy_addressing.canonical_port');

        return is_int($port) && $port > 0 ? $port : null;
    }

    /**
     * Fail-fast boot validation. Called from AppServiceProvider.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function assertValidConfiguration(): void
    {
        $profile = $this->profile();

        if ($profile === 'host_redirect') {
            throw new InvalidArgumentException(
                'TENANCY_ADDRESSING_PROFILE=host_redirect (Profile C) is not implemented in BK-073. Deferred to BK-096.'
            );
        }

        if (! in_array($profile, self::VALID_PROFILES, true)) {
            throw new InvalidArgumentException(
                "Invalid TENANCY_ADDRESSING_PROFILE [{$profile}]. Valid values: host, path."
            );
        }

        $scheme = (string) config('tenancy_addressing.canonical_scheme', '');
        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'TENANCY_CANONICAL_SCHEME must be http or https.'
            );
        }

        if ((bool) config('tenancy_addressing.trust_forwarded_headers', false)) {
            $proxies = (array) config('tenancy_addressing.trusted_proxies', []);
            if ($proxies === [] || in_array('*', $proxies, true)) {
                throw new RuntimeException(
                    'TENANCY_TRUST_FORWARDED_HEADERS is enabled but TENANCY_TRUSTED_PROXIES is empty or contains "*". Explicit IP/CIDR list required.'
                );
            }
        }

        if ($this->isHost()) {
            if ($this->platformBaseDomain() === '') {
                throw new RuntimeException(
                    'TENANT_PLATFORM_BASE_DOMAIN is required when TENANCY_ADDRESSING_PROFILE=host.'
                );
            }

            if ($this->platformHost() === '') {
                throw new RuntimeException(
                    'TENANCY_PLATFORM_HOST (or APP_URL host) is required when TENANCY_ADDRESSING_PROFILE=host.'
                );
            }
        }

        if ($this->isPath() && $this->platformHost() === '') {
            throw new RuntimeException(
                'TENANCY_PLATFORM_HOST (or APP_URL host) is required when TENANCY_ADDRESSING_PROFILE=path.'
            );
        }
    }

    /**
     * Absolute origin for a host (scheme + host + optional configured port). No trailing slash.
     */
    public function absoluteOriginForHost(string $host): string
    {
        $host = strtolower(trim($host));
        $origin = $this->canonicalScheme().'://'.$host;
        $port = $this->canonicalPort();

        if ($port !== null && ! in_array($port, [80, 443], true)) {
            $origin .= ':'.$port;
        }

        return $origin;
    }

    /**
     * Tenant host FQDN for Host profile: {label}.{platform_base_domain}.
     */
    public function tenantHostFqdn(string $label): string
    {
        return strtolower(trim($label)).'.'.$this->platformBaseDomain();
    }
}
