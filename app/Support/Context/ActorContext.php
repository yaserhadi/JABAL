<?php

namespace App\Support\Context;

class ActorContext
{
    private static ?self $instance = null;

    private ?object $actor = null;

    private string $actorType = 'user';

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function set(?object $actor, string $type = 'user'): void
    {
        $this->actor = $actor;
        $this->actorType = $type;
    }

    public function get(): ?object
    {
        return $this->actor;
    }

    public function id(): ?string
    {
        return $this->actor?->id;
    }

    public function type(): string
    {
        return $this->actorType;
    }

    /**
     * Get actor type (alias).
     */
    public function getType(): string
    {
        return $this->type();
    }

    public function isUser(): bool
    {
        return $this->actorType === 'user';
    }

    public function isSystem(): bool
    {
        return $this->actorType === 'system';
    }

    public function toArray(): array
    {
        return [
            'actor_id' => $this->id(),
            'actor_type' => $this->actorType,
        ];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
