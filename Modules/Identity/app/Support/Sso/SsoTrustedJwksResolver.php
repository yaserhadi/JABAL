<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * BK-082 WS7 corrective: trusted JWKS fetch + bounded cache + one refresh on unknown kid (D22).
 *
 * Trust material comes only from configuration-bound issuer / jwks_uri — never from the request.
 */
final class SsoTrustedJwksResolver
{
    public function __construct(
        protected HttpFactory $http,
    ) {}

    /**
     * @return array{keys: list<array<string, mixed>>, uri: string, refreshed: bool}
     */
    public function fetchKeys(string $trustedJwksUri, bool $forceRefresh = false): array
    {
        $uri = $this->assertTrustedHttpsUri($trustedJwksUri);
        $cacheKey = 'sso.jwks.'.hash('sha256', $uri);
        $ttl = max(30, (int) config('identity.sso.jwks_cache_ttl', 300));

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        /** @var array{keys: list<array<string, mixed>>}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['keys']) && is_array($cached['keys']) && ! $forceRefresh) {
            return [
                'keys' => $cached['keys'],
                'uri' => $uri,
                'refreshed' => false,
            ];
        }

        $timeout = max(1, (int) config('identity.sso.jwks_http_timeout', 5));
        $response = $this->http->timeout($timeout)->acceptJson()->get($uri);
        if (! $response->successful()) {
            throw new RuntimeException('jwks_unavailable');
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['keys']) || ! is_array($body['keys'])) {
            throw new RuntimeException('jwks_malformed');
        }

        /** @var list<array<string, mixed>> $keys */
        $keys = [];
        foreach ($body['keys'] as $key) {
            if (is_array($key)) {
                $keys[] = $key;
            }
        }

        Cache::put($cacheKey, ['keys' => $keys], $ttl);

        return [
            'keys' => $keys,
            'uri' => $uri,
            'refreshed' => true,
        ];
    }

    /**
     * Resolve JWKS URI from version-bound jwks_uri or trusted issuer discovery metadata.
     */
    public function resolveJwksUri(?string $configuredJwksUri, string $configuredIssuer): string
    {
        if (is_string($configuredJwksUri) && $configuredJwksUri !== '') {
            return $this->assertTrustedHttpsUri($configuredJwksUri);
        }

        $issuer = rtrim(trim($configuredIssuer), '/');
        if ($issuer === '' || ! str_starts_with($issuer, 'https://')) {
            throw new RuntimeException('issuer_untrusted');
        }

        $metadataUrl = $issuer.'/.well-known/openid-configuration';
        $timeout = max(1, (int) config('identity.sso.jwks_http_timeout', 5));
        $response = $this->http->timeout($timeout)->acceptJson()->get($metadataUrl);
        if (! $response->successful()) {
            throw new RuntimeException('metadata_unavailable');
        }

        $meta = $response->json();
        if (! is_array($meta)) {
            throw new RuntimeException('metadata_malformed');
        }

        $discoveredIssuer = isset($meta['issuer']) && is_string($meta['issuer']) ? rtrim($meta['issuer'], '/') : '';
        if ($discoveredIssuer !== $issuer) {
            throw new RuntimeException('metadata_issuer_mismatch');
        }

        $jwksUri = $meta['jwks_uri'] ?? null;
        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new RuntimeException('jwks_uri_missing');
        }

        return $this->assertTrustedHttpsUri($jwksUri);
    }

    /**
     * Find RSA signing key by kid. Performs at most one trusted JWKS refresh on unknown kid.
     *
     * @param  list<array<string, mixed>>  $keys
     * @return array{key: array<string, mixed>, keys: list<array<string, mixed>>, refreshed: bool}|null
     */
    public function findRsaKeyByKid(string $trustedJwksUri, array $keys, ?string $kid): ?array
    {
        $match = $this->matchRsaKey($keys, $kid);
        if ($match !== null) {
            return ['key' => $match, 'keys' => $keys, 'refreshed' => false];
        }

        // One refresh only on unknown kid (D22).
        $fresh = $this->fetchKeys($trustedJwksUri, forceRefresh: true);
        $match = $this->matchRsaKey($fresh['keys'], $kid);
        if ($match === null) {
            return null;
        }

        return ['key' => $match, 'keys' => $fresh['keys'], 'refreshed' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    protected function matchRsaKey(array $keys, ?string $kid): ?array
    {
        foreach ($keys as $key) {
            if (($key['kty'] ?? null) !== 'RSA') {
                continue;
            }
            // Algorithm confusion: reject oct/symmetric material masquerading in RS path.
            if (isset($key['kty']) && $key['kty'] === 'oct') {
                continue;
            }
            if (isset($key['alg']) && is_string($key['alg']) && $key['alg'] !== 'RS256') {
                continue;
            }
            if (isset($key['use']) && is_string($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if ($kid === null || $kid === '') {
                // OIDC allows missing kid only when a single signing key exists.
                if (count(array_filter($keys, static fn ($k) => ($k['kty'] ?? null) === 'RSA')) === 1) {
                    return $key;
                }

                continue;
            }
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    protected function assertTrustedHttpsUri(string $uri): string
    {
        $trimmed = trim($uri);
        $parts = parse_url($trimmed);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('jwks_uri_untrusted');
        }

        return $trimmed;
    }
}
