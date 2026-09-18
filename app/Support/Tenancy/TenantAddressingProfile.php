<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only accessor for the active Tenant addressing profile (BK-125 / DEC-0023 / DEC-0028).
 *
 * Permanent profiles only: path | path_host.
 * host and host_redirect are rejected (not product profiles).
 *
 * Profile is deployment-wide. No per-Tenant switching.
 */
final class TenantAddressingProfile
{
    public const PROFILE_PATH = 'path';

    public const PROFILE_PATH_HOST = 'path_host';

    /** @var list<string> */
    public const VALID_CANONICAL_PROFILES = [self::PROFILE_PATH, self::PROFILE_PATH_HOST];

    /** @var list<string> */
    public const VALID_CONFIG_PROFILES = [self::PROFILE_PATH, self::PROFILE_PATH_HOST];

    public function rawProfile(): string
    {
        return strtolower(trim((string) config('tenancy_addressing.profile', self::PROFILE_PATH)));
    }

    public function profile(): string
    {
        return $this->rawProfile();
    }

    public function isPathHost(): bool
    {
        return $this->profile() === self::PROFILE_PATH_HOST;
    }

    /**
     * Compatibility synonym for PATH_HOST semantics.
     * Does NOT mean TENANCY_ADDRESSING_PROFILE=host (that env value is rejected).
     */
    public function isHost(): bool
    {
        return $this->isPathHost();
    }

    public function isPath(): bool
    {
        return $this->profile() === self::PROFILE_PATH;
    }

    /**
     * Whether an env/config profile string selects PATH_HOST semantics.
     */
    public static function configSelectsPathHost(string $profile): bool
    {
        return strtolower(trim($profile)) === self::PROFILE_PATH_HOST;
    }

    public function platformBaseDomain(): string
    {
        return (string) config('tenancy_addressing.platform_base_domain', '');
    }

    /**
     * Public Apex host for Tenant discovery /register (BK-125 Wave 4 / DEC-0028 §C).
     *
     * PATH: shared deployment host (platformHost).
     * PATH_HOST: platform base domain (apex of the wildcard tree) — never Platform Operator host.
     */
    public function apexHost(): string
    {
        if ($this->isPathHost()) {
            return $this->platformBaseDomain();
        }

        return $this->platformHost();
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
        $raw = $this->rawProfile();

        if ($raw === 'host' || $raw === 'host_redirect') {
            throw new InvalidArgumentException(
                "TENANCY_ADDRESSING_PROFILE [{$raw}] is not a supported product profile (BK-125 Wave 8)."
                .' Valid values: path, path_host.'
            );
        }

        if (! in_array($raw, self::VALID_CONFIG_PROFILES, true)) {
            throw new InvalidArgumentException(
                "Invalid TENANCY_ADDRESSING_PROFILE [{$raw}]. Valid values: path, path_host."
            );
        }

        $scheme = (string) config('tenancy_addressing.canonical_scheme', '');
        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'TENANCY_CANONICAL_SCHEME must be http or https.'
            );
        }

        $proxies = array_values(array_filter(
            array_map('trim', (array) config('tenancy_addressing.trusted_proxies', [])),
            static fn (string $proxy): bool => $proxy !== ''
        ));

        if (in_array('*', $proxies, true)) {
            throw new RuntimeException(
                'TENANCY_TRUSTED_PROXIES must not contain "*". Explicit IP/CIDR list required; never trust all proxies.'
            );
        }

        if ((bool) config('tenancy_addressing.trust_forwarded_headers', false)) {
            if ($proxies === []) {
                throw new RuntimeException(
                    'TENANCY_TRUST_FORWARDED_HEADERS is enabled but TENANCY_TRUSTED_PROXIES is empty. Explicit IP/CIDR list required.'
                );
            }
        }

        if ($this->isPathHost()) {
            if ($this->platformBaseDomain() === '') {
                throw new RuntimeException(
                    'TENANT_PLATFORM_BASE_DOMAIN is required when TENANCY_ADDRESSING_PROFILE=path_host.'
                );
            }

            if ($this->platformHost() === '') {
                throw new RuntimeException(
                    'TENANCY_PLATFORM_HOST (or APP_URL host) is required when TENANCY_ADDRESSING_PROFILE=path_host.'
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
     * Tenant host FQDN for PATH_HOST: {label}.{platform_base_domain}.
     */
    public function tenantHostFqdn(string $label): string
    {
        return strtolower(trim($label)).'.'.$this->platformBaseDomain();
    }
}
