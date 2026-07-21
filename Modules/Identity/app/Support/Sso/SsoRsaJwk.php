<?php

namespace Modules\Identity\Support\Sso;

/**
 * BK-082 WS7 corrective: RSA JWK ↔ PEM helpers for RS256 logout-token verification.
 */
final class SsoRsaJwk
{
    /**
     * @param  array<string, mixed>  $jwk
     */
    public static function toPublicPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }
        if (! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }

        $modulus = self::b64urlDecode($jwk['n']);
        $exponent = self::b64urlDecode($jwk['e']);
        if ($modulus === null || $exponent === null) {
            return null;
        }

        $components = [
            'modulus' => pack('Ca*a*', 0x02, self::encodeLength(strlen($modulus)), $modulus),
            'publicExponent' => pack('Ca*a*', 0x02, self::encodeLength(strlen($exponent)), $exponent),
        ];
        $rsaPublicKey = $components['modulus'].$components['publicExponent'];
        $rsaPublicKey = pack('Ca*a*', 0x30, self::encodeLength(strlen($rsaPublicKey)), $rsaPublicKey);
        $rsaOid = pack('H*', '300d06092a864886f70d0101010500');
        $rsaPublicKey = chr(0x00).$rsaPublicKey;
        $rsaPublicKey = chr(0x03).self::encodeLength(strlen($rsaPublicKey)).$rsaPublicKey;
        $rsaPublicKey = pack('Ca*a*', 0x30, self::encodeLength(strlen($rsaOid.$rsaPublicKey)), $rsaOid.$rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($rsaPublicKey), 64, "\n").'-----END PUBLIC KEY-----';
    }

    /**
     * Load a deterministic RSA fixture (preferred — no OpenSSL config dependency).
     *
     * @return array{private_pem: string, jwk: array<string, mixed>}
     */
    public static function loadFixtureKeyPair(string $name = 'primary'): array
    {
        $path = base_path('tests/Support/fixtures/sso_rs256_keys.json');
        if (! is_file($path)) {
            throw new \RuntimeException('RSA fixture file missing: tests/Support/fixtures/sso_rs256_keys.json');
        }

        /** @var array<string, array{private_pem: string, jwk: array<string, mixed>}> $all */
        $all = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! isset($all[$name]['private_pem'], $all[$name]['jwk']) || ! is_array($all[$name]['jwk'])) {
            throw new \RuntimeException('Unknown RSA fixture: '.$name);
        }

        return [
            'private_pem' => $all[$name]['private_pem'],
            'jwk' => $all[$name]['jwk'],
        ];
    }

    /**
     * @return array{private_pem: string, jwk: array<string, mixed>}
     */
    public static function generateFixtureKeyPair(string $kid = 'test-kid-1'): array
    {
        $resource = self::newRsaResource();
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate RSA key for fixture.');
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Unable to export RSA details for fixture.');
        }

        $jwk = [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64urlEncode($details['rsa']['n']),
            'e' => self::b64urlEncode($details['rsa']['e']),
        ];

        return [
            'private_pem' => $privatePem,
            'jwk' => $jwk,
        ];
    }

    /**
     * @return \OpenSSLAsymmetricKey|resource|false
     */
    protected static function newRsaResource()
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);
        if ($resource !== false) {
            return $resource;
        }

        // Windows/XAMPP often lacks OPENSSL_CONF; retry with a known apache openssl.cnf.
        if (getenv('OPENSSL_CONF') === false || getenv('OPENSSL_CONF') === '') {
            foreach ([
                'C:\\xampp\\apache\\conf\\openssl.cnf',
                '/etc/ssl/openssl.cnf',
                '/etc/pki/tls/openssl.cnf',
            ] as $candidate) {
                if (! is_file($candidate)) {
                    continue;
                }
                putenv('OPENSSL_CONF='.$candidate);
                $resource = openssl_pkey_new($config);
                if ($resource !== false) {
                    return $resource;
                }
            }
        }

        return false;
    }

    public static function b64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    protected static function encodeLength(int $length): string
    {
        if ($length <= 0x7F) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), chr(0));

        return chr(0x80 | strlen($temp)).$temp;
    }
}
