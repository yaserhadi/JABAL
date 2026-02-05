<?php

namespace App\Support\Audit;

use App\Support\Contracts\Audit\AuditLoggerInterface;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->audit('created'));
        static::updated(fn ($model) => $model->audit('updated'));
        static::deleted(fn ($model) => $model->audit('deleted'));
    }

    public function audit(string $event): void
    {
        $logger = app(AuditLoggerInterface::class);

        match ($event) {
            'created' => $logger->logCreated($this),
            'updated' => $logger->logUpdated($this, $this->getOriginal(), $this->getAttributes()),
            'deleted' => $logger->logDeleted($this),
            default => $logger->log($event, [
                'auditable_type' => $this::class,
                'auditable_id' => $this->getKey(),
                'new_values' => $this->getAttributes(),
            ]),
        };
    }
}
