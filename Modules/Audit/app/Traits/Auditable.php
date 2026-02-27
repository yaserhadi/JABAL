<?php

namespace Modules\Audit\Traits;

use App\Support\Context\ActorContext;
use App\Support\Context\RequestContext;
use App\Support\Context\TenantContext;
use Modules\Audit\Models\AuditLog;

trait Auditable
{
    /**
     * Boot the trait.
     */
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->auditEvent('created');
        });

        static::updated(function ($model) {
            if ($model->wasChanged() && !$model->wasRecentlyCreated) {
                $model->auditEvent('updated');
            }
        });

        static::deleted(function ($model) {
            $model->auditEvent('deleted');
        });
    }

    /**
     * Create an audit log entry.
     */
    public function auditEvent(string $event, array $metadata = []): void
    {
        $requestContext = RequestContext::getInstance();
        $tenantContext = TenantContext::getInstance();
        $actorContext = ActorContext::getInstance();

        AuditLog::create([
            'tenant_id' => $tenantContext->getTenantId(),
            'user_id' => auth()->id(),
            'actor_type' => $actorContext->getType(),
            'request_id' => $requestContext->getRequestId(),
            'event' => $event,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'old_values' => $this->getOriginal(),
            'new_values' => $this->getAttributes(),
            'ip_address' => $requestContext->getIp(),
            'user_agent' => $requestContext->getUserAgent(),
            'url' => $requestContext->getUrl(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get audit logs for this model.
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
