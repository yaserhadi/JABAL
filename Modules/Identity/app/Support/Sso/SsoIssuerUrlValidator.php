<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Exceptions\SsoSecurityException;

/**
 * SSRF-safe validation for tenant-configured OIDC issuer URLs (Area 17).
 */
final class SsoIssuerUrlValidator
{
    /** @var list<string> */
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'metadata.google.internal',
    ];

    public function validateConfiguredIssuer(string $issuerUrl): string
    {
        $issuerUrl = trim($issuerUrl);

        if ($issuerUrl === '') {
            throw new SsoSecurityException('Issuer URL is required.');
        }

        $parts = parse_url($issuerUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsoSecurityException('Issuer URL is invalid.');
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new SsoSecurityException('Issuer URL must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsoSecurityException('Issuer URL must not include credentials.');
        }

        $host = strtolower((string) $parts['host']);

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new SsoSecurityException('Issuer host is not allowed.');
        }

        $this->assertHostResolvesToPublicIps($host);

        return rtrim($issuerUrl, '/');
    }

    public function assertDiscoveredIssuerMatches(string $configuredIssuer, string $discoveredIssuer): void
    {
        $configured = rtrim(trim($configuredIssuer), '/');
        $discovered = rtrim(trim($discoveredIssuer), '/');

        if ($configured !== $discovered) {
            throw new SsoSecurityException('Discovered issuer does not match configured issuer.');
        }
    }

    private function assertHostResolvesToPublicIps(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpIsPublic($host);

            return;
        }

        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            throw new SsoSecurityException('Issuer host could not be resolved.');
        }

        foreach ($ips as $ip) {
            $this->assertIpIsPublic($ip);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A);
        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function assertIpIsPublic(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new SsoSecurityException('Issuer resolved to an invalid IP address.');
        }

        if (
            ! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )
        ) {
            throw new SsoSecurityException('Issuer resolves to a non-public IP address.');
        }

        $blockedLiteralHosts = ['127.0.0.1', '0.0.0.0', '::1', '::'];
        if (in_array(strtolower($ip), $blockedLiteralHosts, true)) {
            throw new SsoSecurityException('Issuer resolves to a blocked address.');
        }
    }
}
