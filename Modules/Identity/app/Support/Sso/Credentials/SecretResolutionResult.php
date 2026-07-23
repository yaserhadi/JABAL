<?php

namespace Modules\Identity\Support\Sso\Credentials;

final class SecretResolutionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason = null,
        /** @var non-empty-string|null Transient resolved material — never persist or log. */
        private readonly ?string $value = null,
    ) {}

    public static function success(string $value): self
    {
        if ($value === '') {
            return self::failure('empty_resolved_value');
        }

        return new self(true, null, $value);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason, null);
    }

    /**
     * Consume the resolved secret exactly once for the caller's immediate use.
     * Does not log or expose via __debugInfo.
     */
    public function consumeValue(): ?string
    {
        return $this->value;
    }

    public function __debugInfo(): array
    {
        return [
            'ok' => $this->ok,
            'reason' => $this->reason,
            'value' => $this->ok ? '[redacted]' : null,
        ];
    }
}
