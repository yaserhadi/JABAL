<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Session\AuthSessionInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Override;

/**
 * Laravel session persistence for Facile AuthSessionInterface (state, nonce, PKCE verifier).
 */
final class LaravelSessionAuthSessionAdapter implements AuthSessionInterface
{
    private ?string $state = null;

    private ?string $nonce = null;

    private ?string $codeVerifier = null;

    /** @var array<string, mixed> */
    private array $customs = [];

    public function __construct(
        private readonly Session $session,
        private readonly string $tenantId,
    ) {}

    public static function sessionKey(string $tenantId): string
    {
        $prefix = (string) config('identity.sso.session_key_prefix', 'sso.auth.');

        return $prefix.$tenantId;
    }

    public static function load(Session $session, string $tenantId): ?self
    {
        $payload = $session->get(self::sessionKey($tenantId));

        if (! is_array($payload)) {
            return null;
        }

        return self::fromStoredArray($session, $tenantId, $payload);
    }

    public static function pull(Session $session, string $tenantId): ?self
    {
        $payload = $session->pull(self::sessionKey($tenantId));

        if (! is_array($payload)) {
            return null;
        }

        return self::fromStoredArray($session, $tenantId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromStoredArray(Session $session, string $tenantId, array $payload): self
    {
        $adapter = new self($session, $tenantId);
        $adapter->setState(isset($payload['state']) ? (string) $payload['state'] : null);
        $adapter->setNonce(isset($payload['nonce']) ? (string) $payload['nonce'] : null);
        $adapter->setCodeVerifier(isset($payload['code_verifier']) ? (string) $payload['code_verifier'] : null);
        $adapter->setCustoms(is_array($payload['customs'] ?? null) ? $payload['customs'] : []);

        return $adapter;
    }

    public function persist(): void
    {
        $this->session->put(self::sessionKey($this->tenantId), $this->jsonSerialize());
    }

    public function clear(): void
    {
        $this->session->forget(self::sessionKey($this->tenantId));
    }

    public function initializeForAuthorization(string $codeVerifier, ?string $oidcState = null): void
    {
        $this->setState($oidcState ?? Str::random(40));
        $this->setNonce(Str::random(40));
        $this->setCodeVerifier($codeVerifier);
        $this->setCustoms(['tenant_id' => $this->tenantId]);
        $this->persist();
    }

    #[Override]
    public function getState(): ?string
    {
        return $this->state;
    }

    #[Override]
    public function getNonce(): ?string
    {
        return $this->nonce;
    }

    #[Override]
    public function getCodeVerifier(): ?string
    {
        return $this->codeVerifier;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getCustoms(): array
    {
        return $this->customs;
    }

    #[Override]
    public function setState(?string $state): void
    {
        $this->state = $state;
    }

    #[Override]
    public function setNonce(?string $nonce): void
    {
        $this->nonce = $nonce;
    }

    #[Override]
    public function setCodeVerifier(?string $codeVerifier): void
    {
        $this->codeVerifier = $codeVerifier;
    }

    /**
     * @param  array<string, mixed>  $customs
     */
    #[Override]
    public function setCustoms(array $customs): void
    {
        $this->customs = $customs;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    #[Override]
    public static function fromArray(array $array): AuthSessionInterface
    {
        $adapter = new self(session(), (string) ($array['customs']['tenant_id'] ?? ''));
        $adapter->setState($array['state'] ?? null);
        $adapter->setNonce($array['nonce'] ?? null);
        $adapter->setCodeVerifier($array['code_verifier'] ?? null);
        $adapter->setCustoms(is_array($array['customs'] ?? null) ? $array['customs'] : []);

        return $adapter;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return array_filter([
            'state' => $this->getState(),
            'nonce' => $this->getNonce(),
            'code_verifier' => $this->getCodeVerifier(),
            'customs' => $this->getCustoms(),
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
