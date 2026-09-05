<?php

namespace Modules\Audit\Http\Controllers;

use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Modules\Identity\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Audit\Models\AuditLog;

/**
 * Tenant-scoped audit timeline (BK-020) — read central audit_logs filtered by tenant_id.
 */
class TenantAuditController extends Controller
{
    /** @var array<int, string> */
    private const FORBIDDEN_KEYS = ['token', 'token_hash', 'password', 'secret', 'api_key', 'plainToken'];

    /** @var array<int, string> */
    private const METADATA_ALLOWLIST = ['ip', 'request_id'];

    /** @var array<string, string> */
    private const EVENT_LABELS = [
        'tenant_member.invited' => 'Invitation sent',
        'tenant_member.invitation_reissued' => 'Invitation resent',
        'tenant_member.invitation_accepted' => 'Invitation accepted',
        'tenant_member.invitation_revoked' => 'Invitation revoked',
        'tenant_member.removed' => 'Member removed',
        'tenant_member.restored' => 'Member restored',
        'tenant_member.permanently_deleted' => 'Member permanently deleted',
        'tenant_member.role_changed' => 'Role changed',
        'tenant_member.suspended' => 'Member suspended',
        'tenant_member.activated' => 'Member activated',
        'tenant_member.ownership_transferred' => 'Ownership transferred',
    ];

    public function __construct()
    {
        $this->middleware('permission:tenant.audit.view')->only(['index']);
    }

    public function index(Request $request): InertiaResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $query = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at');

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        /** @var LengthAwarePaginator<int, AuditLog> $paginator */
        $paginator = $query->paginate(20)->withQueryString();
        $users = $this->resolveUsers($this->collectUserIds($paginator->getCollection()));

        $entries = $paginator->getCollection()->map(
            fn (AuditLog $log) => $this->formatEntry($log, $users)
        )->values();

        return Inertia::render('TenantAudit/Index', [
            'tenant' => TenantInertiaProps::from($tenant),
            'logs' => [
                'data' => $entries,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'filters' => [
                'event' => $request->input('event'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            'eventTypes' => array_keys(self::EVENT_LABELS),
        ]);
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, string>
     */
    private function collectUserIds(Collection $logs): array
    {
        $ids = [];
        foreach ($logs as $log) {
            if ($log->actor_id) {
                $ids[] = $log->actor_id;
            }
            foreach (['invited_by_user_id', 'reissued_by_user_id', 'user_id'] as $key) {
                $id = $log->new_values[$key] ?? $log->old_values[$key] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $userIds
     * @return Collection<string, User>
     */
    private function resolveUsers(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return TenantUser::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<string, User>  $users
     * @return array<string, mixed>
     */
    private function formatEntry(AuditLog $log, Collection $users): array
    {
        $newValues = $this->sanitizeArray($log->new_values);
        $oldValues = $this->sanitizeArray($log->old_values);
        $metadata = $this->sanitizeMetadata($log->metadata);

        return [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => self::EVENT_LABELS[$log->event] ?? $log->event,
            'occurred_at' => $log->created_at?->toIso8601String(),
            'actor' => $this->formatActor($log->actor_id, $users),
            'target' => $this->formatTarget($newValues, $oldValues, $users),
            'details' => $this->formatDetails($log->event, $newValues, $oldValues),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  Collection<string, User>  $users
     * @return array{label: string, email: ?string}
     */
    private function formatActor(?string $userId, Collection $users): array
    {
        if (! $userId) {
            return ['label' => 'System', 'email' => null];
        }

        $user = $users->get($userId);
        if ($user) {
            return ['label' => $user->name, 'email' => $user->email];
        }

        return ['label' => 'Former member', 'email' => null];
    }

    /**
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     * @param  Collection<string, User>  $users
     * @return array{label: string, email: ?string}
     */
    private function formatTarget(?array $newValues, ?array $oldValues, Collection $users): array
    {
        $email = $newValues['email'] ?? $oldValues['email'] ?? null;
        if (is_string($email) && $email !== '') {
            return ['label' => $email, 'email' => $email];
        }

        $userId = $newValues['user_id'] ?? null;
        if (is_string($userId) && $userId !== '') {
            $user = $users->get($userId);

            return $user
                ? ['label' => $user->name, 'email' => $user->email]
                : ['label' => 'Former member', 'email' => null];
        }

        return ['label' => '—', 'email' => null];
    }

    /**
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     * @return array<string, mixed>
     */
    private function formatDetails(string $event, ?array $newValues, ?array $oldValues): array
    {
        $details = [];

        if ($event === 'tenant_member.invited' && isset($newValues['invited_by_user_id'])) {
            $details['invited_by_user_id'] = $newValues['invited_by_user_id'];
        }
        if ($event === 'tenant_member.invitation_reissued' && isset($newValues['reissued_by_user_id'])) {
            $details['reissued_by_user_id'] = $newValues['reissued_by_user_id'];
        }
        if (isset($newValues['expires_at'])) {
            $details['expires_at'] = $newValues['expires_at'];
        }
        if (isset($newValues['role'])) {
            $details['role'] = $newValues['role'];
        }
        if ($event === 'tenant_member.role_changed') {
            if (isset($oldValues['role'])) {
                $details['from_role'] = $oldValues['role'];
            }
            if (isset($newValues['role'])) {
                $details['to_role'] = $newValues['role'];
            }
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function sanitizeArray(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitizeArray($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $safe = [];
        foreach (self::METADATA_ALLOWLIST as $key) {
            if (array_key_exists($key, $metadata)) {
                $safe[$key] = $metadata[$key];
            }
        }

        return $safe;
    }
}
