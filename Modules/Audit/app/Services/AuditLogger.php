<?php

namespace Modules\Audit\Services;

use App\Support\Context\RequestContext;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;

class AuditLogger implements AuditLoggerInterface
{
    public function log(string $event, array $context = []): void
    {
        $requestContext = RequestContext::getInstance();

        AuditLog::create([
            'tenant_id' => $context['tenant_id'] ?? $this->currentTenantId(),
            'actor_id' => $context['actor_id'] ?? auth()->id(),
            'actor_type' => $context['actor_type'] ?? 'user',
            'event' => $event,
            'auditable_type' => $context['auditable_type'] ?? '',
            'auditable_id' => $context['auditable_id'] ?? Str::uuid()->toString(),
            'old_values' => $context['old_values'] ?? null,
            'new_values' => $context['new_values'] ?? null,
            'metadata' => [
                'request_id' => $requestContext->requestId(),
                'ip' => $requestContext->ip(),
                'user_agent' => $requestContext->userAgent(),
                ...($context['metadata'] ?? []),
            ],
            'created_at' => now(),
        ]);
    }

    public function logCreated(object $model): void
    {
        $this->log(
            $this->eventName($model, 'created'),
            [
                'auditable_type' => $model::class,
                'auditable_id' => $model->getKey(),
                'new_values' => $model->getAttributes(),
            ]
        );
    }

    public function logUpdated(object $model, array $oldValues, array $newValues): void
    {
        $this->log(
            $this->eventName($model, 'updated'),
            [
                'auditable_type' => $model::class,
                'auditable_id' => $model->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]
        );
    }

    public function logDeleted(object $model): void
    {
        $this->log(
            $this->eventName($model, 'deleted'),
            [
                'auditable_type' => $model::class,
                'auditable_id' => $model->getKey(),
                'old_values' => $model->getAttributes(),
            ]
        );
    }

    private function eventName(object $model, string $action): string
    {
        $shortName = class_basename($model);

        return strtolower($shortName).'.'.$action;
    }

    private function currentTenantId(): ?string
    {
        $tenant = \App\Support\Context\TenantContext::getInstance()->get();
        if ($tenant?->id) {
            return $tenant->id;
        }

        if (function_exists('tenancy') && tenancy()->initialized) {
            return tenancy()->tenant?->id;
        }

        return null;
    }
}
