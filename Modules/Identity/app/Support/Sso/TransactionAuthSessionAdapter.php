<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Session\AuthSessionInterface;
use Override;

/**
 * BK-082 WS4: in-memory Facile AuthSession from central transaction materials (not Laravel session).
 */
final class TransactionAuthSessionAdapter implements AuthSessionInterface
{
    /** @var array<string, mixed> */
    private array $customs = [];

    public function __construct(
        private ?string $state,
        private ?string $nonce,
        private ?string $codeVerifier,
    ) {}

    public static function fromTransactionMaterials(string $state, string $nonce, string $pkceVerifier): self
    {
        return new self($state, $nonce, $pkceVerifier);
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
        $adapter = new self(
            isset($array['state']) ? (string) $array['state'] : null,
            isset($array['nonce']) ? (string) $array['nonce'] : null,
            isset($array['code_verifier']) ? (string) $array['code_verifier'] : null,
        );
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
