<?php

namespace Modules\Identity\Support\Sso;

/**
 * BK-082 WS7: parse/validate OIDC Back-Channel Logout Token (D22/D26).
 */
final class SsoBackChannelLogoutTokenValidator
{
    public const EVENT_TYPE = 'http://schemas.openid.net/event/backchannel-logout';

    /** Platform allowlist — config version must still explicitly enable each alg. */
    public const PLATFORM_ALLOWED_ALGS = ['RS256', 'HS256'];

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signing_input: string, signature: string}|null
     */
    public function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        $headerJson = SsoRsaJwk::b64urlDecode($parts[0]);
        $payloadJson = SsoRsaJwk::b64urlDecode($parts[1]);
        if ($headerJson === null || $payloadJson === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'signing_input' => $parts[0].'.'.$parts[1],
            'signature' => $parts[2],
        ];
    }

    /**
     * @param  list<string>  $configuredAlgs
     */
    public function assertAlgorithmAllowed(?string $alg, array $configuredAlgs): ?string
    {
        if (! is_string($alg) || $alg === '' || strtolower($alg) === 'none') {
            return 'alg_none_or_missing';
        }

        if (! in_array($alg, self::PLATFORM_ALLOWED_ALGS, true)) {
            return 'alg_unsupported';
        }

        if (! in_array($alg, $configuredAlgs, true)) {
            return 'alg_not_configured';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateClaims(array $payload, string $expectedIssuer, string $expectedAudience): ?string
    {
        $iss = $payload['iss'] ?? null;
        if (! is_string($iss) || rtrim($iss, '/') !== rtrim($expectedIssuer, '/')) {
            return 'issuer_mismatch';
        }

        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        $audienceOk = false;
        foreach ($audiences as $value) {
            if (is_string($value) && $value === $expectedAudience) {
                $audienceOk = true;
                break;
            }
        }
        if (! $audienceOk) {
            return 'audience_mismatch';
        }

        if (isset($payload['nonce'])) {
            return 'nonce_present';
        }

        $events = $payload['events'] ?? null;
        if (! is_array($events) || ! array_key_exists(self::EVENT_TYPE, $events)) {
            return 'events_missing';
        }

        $jti = $payload['jti'] ?? null;
        if (! is_string($jti) || $jti === '') {
            return 'jti_missing';
        }

        $sub = $payload['sub'] ?? null;
        $sid = $payload['sid'] ?? null;
        if ((! is_string($sub) || $sub === '') && (! is_string($sid) || $sid === '')) {
            return 'sub_or_sid_required';
        }

        $iat = $payload['iat'] ?? null;
        if (! is_numeric($iat)) {
            return 'iat_missing';
        }

        $now = time();
        if ((int) $iat > ($now + 60)) {
            return 'iat_in_future';
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < ($now - 60)) {
            return 'expired';
        }

        return null;
    }

    public function verifyHmacSha256(string $signingInput, string $signatureB64Url, string $clientSecret): bool
    {
        $expected = hash_hmac('sha256', $signingInput, $clientSecret, true);
        $actual = SsoRsaJwk::b64urlDecode($signatureB64Url);

        return $actual !== null && hash_equals($expected, $actual);
    }

    public function verifyRs256(string $signingInput, string $signatureB64Url, string $publicPem): bool
    {
        $signature = SsoRsaJwk::b64urlDecode($signatureB64Url);
        if ($signature === null) {
            return false;
        }

        $result = openssl_verify($signingInput, $signature, $publicPem, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headerExtra
     */
    public function mintHmacSha256ForTests(array $payload, string $clientSecret, array $headerExtra = []): string
    {
        $header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $headerExtra);
        $headerPart = SsoRsaJwk::b64urlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $body = SsoRsaJwk::b64urlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerPart.'.'.$body;
        $sig = SsoRsaJwk::b64urlEncode(hash_hmac('sha256', $signingInput, $clientSecret, true));

        return $signingInput.'.'.$sig;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headerExtra
     */
    public function mintRs256ForTests(array $payload, string $privatePem, array $headerExtra = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT'], $headerExtra);
        $headerPart = SsoRsaJwk::b64urlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $body = SsoRsaJwk::b64urlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerPart.'.'.$body;
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privatePem, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new \RuntimeException('Unable to sign RS256 fixture.');
        }

        return $signingInput.'.'.SsoRsaJwk::b64urlEncode($signature);
    }
}
