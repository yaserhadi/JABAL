<?php

namespace App\Support\Context;

class ExecutionContext
{
    private static ?self $instance = null;

    private string $mode = 'unknown';

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isWeb(): bool
    {
        return $this->mode === 'web';
    }

    public function isApi(): bool
    {
        return $this->mode === 'api';
    }

    public function isJob(): bool
    {
        return $this->mode === 'job';
    }

    public function isCli(): bool
    {
        return $this->mode === 'cli';
    }

    public function isTest(): bool
    {
        return $this->mode === 'test';
    }

    public function toArray(): array
    {
        return [
            'execution_mode' => $this->mode,
        ];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
