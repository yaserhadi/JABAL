<?php

namespace Modules\Identity\Support\Sso;

/**
 * BK-082 WS7: parse and validate OIDC Back-Channel Logout Token claims (D26).
 *
 * Signature verification is performed by the caller after structural validation
 * (or via {@see verifyHmacSha256} for HS256 client_secret profiles / tests).
 */
final class SsoBackChannelLogoutTokenValidator
{
    public const EVENT_TYPE = 'http://schemas.openid.net/event/backchannel-logout';

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signing_input: string, signature: string}|null
     */
    public function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        $headerJson = $this->b64urlDecode($parts[0]);
        $payloadJson = $this->b64urlDecode($parts[1]);
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
        $actual = $this->b64urlDecode($signatureB64Url);
        if ($actual === null) {
            return false;
        }

        return hash_equals($expected, $actual);
    }

    /**
     * Build a compact HS256 JWT for protocol fixture tests (not for production minting).
     *
     * @param  array<string, mixed>  $payload
     */
    public function mintHmacSha256ForTests(array $payload, string $clientSecret): string
    {
        $header = $this->b64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->b64urlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$body;
        $sig = $this->b64urlEncode(hash_hmac('sha256', $signingInput, $clientSecret, true));

        return $signingInput.'.'.$sig;
    }

    protected function b64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function b64urlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
