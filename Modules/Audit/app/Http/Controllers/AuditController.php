<?php

namespace Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Audit\Models\AuditLog;

class AuditController extends Controller
{
    /**
     * List audit logs with optional filters.
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }
        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }
        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->input('auditable_type'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $logs = $query->paginate(20);

        return view('audit::index', ['logs' => $logs]);
    }

    /**
     * Show audit log detail (diff viewer).
     */
    public function show(string $id)
    {
        $log = AuditLog::findOrFail($id);

        return view('audit::show', ['log' => $log]);
    }
}
