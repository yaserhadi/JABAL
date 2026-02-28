<?php

namespace App\Support\Contracts\Audit;

interface AuditLoggerInterface
{
    /**
     * Log an audit event.
     *
     * @param  string  $event  Event name (e.g., 'user.created', 'tenant.updated')
     * @param  array  $context  Additional context data
     */
    public function log(string $event, array $context = []): void;

    /**
     * Log a model creation event.
     */
    public function logCreated(object $model): void;

    /**
     * Log a model update event.
     */
    public function logUpdated(object $model, array $oldValues, array $newValues): void;

    /**
     * Log a model deletion event.
     */
    public function logDeleted(object $model): void;
}
