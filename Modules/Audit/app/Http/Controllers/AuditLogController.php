<?php

namespace Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Audit\Models\AuditLog;

class AuditLogController extends Controller
{
    /**
     * Display audit logs with filters.
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()->with(['user', 'tenant'])->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->filled('event')) {
            $query->byEvent($request->event);
        }

        if ($request->filled('user_id')) {
            $query->byUser($request->user_id);
        }

        if ($request->filled('auditable_type')) {
            $query->byAuditableType($request->auditable_type);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50)->through(fn ($log) => [
            'id' => $log->id,
            'event' => $log->event,
            'auditable_type' => class_basename($log->auditable_type),
            'auditable_id' => $log->auditable_id,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'actor_type' => $log->actor_type,
            'changes_summary' => $log->getChangesSummary(),
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at->toDateTimeString(),
        ]);

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only(['event', 'user_id', 'auditable_type', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show detailed audit log.
     */
    public function show(AuditLog $auditLog)
    {
        $auditLog->load(['user', 'tenant']);

        return Inertia::render('Audit/Show', [
            'log' => [
                'id' => $auditLog->id,
                'event' => $auditLog->event,
                'auditable_type' => $auditLog->auditable_type,
                'auditable_id' => $auditLog->auditable_id,
                'user' => $auditLog->user ? [
                    'id' => $auditLog->user->id,
                    'name' => $auditLog->user->name,
                    'email' => $auditLog->user->email,
                ] : null,
                'tenant' => $auditLog->tenant ? [
                    'id' => $auditLog->tenant->id,
                    'name' => $auditLog->tenant->name,
                ] : null,
                'actor_type' => $auditLog->actor_type,
                'request_id' => $auditLog->request_id,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
                'url' => $auditLog->url,
                'description' => $auditLog->description,
                'metadata' => $auditLog->metadata,
                'created_at' => $auditLog->created_at->toDateTimeString(),
            ],
        ]);
    }
}
