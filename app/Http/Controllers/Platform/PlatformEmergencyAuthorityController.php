<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformEmergencyAuthorityCase;
use App\Models\PlatformUser;
use App\Services\Platform\PlatformEmergencyAuthorityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5: Platform Emergency Authority surface (distinct from Tenant admin).
 */
class PlatformEmergencyAuthorityController extends Controller
{
    public function __construct(
        protected PlatformEmergencyAuthorityService $pea,
    ) {}

    public function index(Request $request): Response
    {
        $cases = PlatformEmergencyAuthorityCase::query()
            ->orderByDesc('activated_at')
            ->limit(50)
            ->get();

        return Inertia::render('Platform/Emergency/Index', [
            'cases' => $cases,
            'tenants' => Tenant::query()->orderBy('slug')->limit(200)->get(['id', 'slug', 'name', 'status']),
        ]);
    }

    public function invoke(Request $request): RedirectResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user('platform');
        abort_unless($actor, 403);

        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:512'],
            'classification' => ['required', 'in:availability,compromise'],
            'emergency_tenant_user_id' => ['nullable', 'uuid'],
            'enable_temporary_password' => ['sometimes', 'boolean'],
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
        ]);

        $tenant = Tenant::query()->findOrFail($validated['tenant_id']);
        $target = null;
        if (! empty($validated['emergency_tenant_user_id'])) {
            tenancy()->initialize($tenant);
            try {
                $target = TenantUser::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($validated['emergency_tenant_user_id'])
                    ->firstOrFail();
            } finally {
                tenancy()->end();
            }
        }

        $this->pea->invoke(
            $actor,
            $tenant,
            $validated['reason'],
            $validated['classification'],
            $target,
            (bool) ($validated['enable_temporary_password'] ?? true),
            (int) ($validated['ttl_hours'] ?? 24),
        );

        return back()->with('success', 'Platform Emergency Authority invoked.');
    }

    public function close(Request $request, string $caseId): RedirectResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user('platform');
        abort_unless($actor, 403);

        $case = PlatformEmergencyAuthorityCase::query()->findOrFail($caseId);
        $validated = $request->validate([
            'close_reason' => ['sometimes', 'string', 'max:128'],
        ]);

        $this->pea->close(
            $actor,
            $case,
            $validated['close_reason'] ?? 'return_to_normal',
        );

        return back()->with('success', 'PEA case closed; temporary recoveries revoked.');
    }
}
