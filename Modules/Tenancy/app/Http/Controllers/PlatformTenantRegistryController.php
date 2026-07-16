<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tenancy\Http\Requests\CheckTenantHandleAvailabilityRequest;
use Modules\Tenancy\Http\Requests\PlatformCreateTenantRequest;
use Modules\Tenancy\Http\Requests\PlatformUpdateTenantRequest;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\PlatformTenantRegistryService;

class PlatformTenantRegistryController extends Controller
{
    public function index(Request $request, PlatformTenantRegistryService $registry): Response
    {
        $tenants = $registry->list([
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'isolation_level' => $request->string('isolation_level')->toString() ?: null,
            'provisioning_status' => $request->string('provisioning_status')->toString() ?: null,
            'per_page' => $request->integer('per_page', 15),
        ]);

        return Inertia::render('Platform/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $request->string('status')->toString(),
                'isolation_level' => $request->string('isolation_level')->toString(),
                'provisioning_status' => $request->string('provisioning_status')->toString(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Tenants/Create', [
            'default_isolation_level' => (string) config('tenancy_storage.default_isolation_level', 'shared'),
        ]);
    }

    public function store(
        PlatformCreateTenantRequest $request,
        PlatformTenantRegistryService $registry,
    ): RedirectResponse|JsonResponse {
        $result = $registry->create(
            $request->validated(),
            $request->user('platform')?->getAuthIdentifier(),
        );

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result['payload'], $result['http_status']);
        }

        return redirect()
            ->route('platform.tenants.show', $result['payload']['id'])
            ->with('success', $result['http_status'] === 201
                ? 'Tenant created. Provisioning completed for the default isolation mode.'
                : 'Tenant created. Provisioning action required — see provisioning status on this page.')
            ->with('provisioning_status', $result['payload']['provisioning_status']);
    }

    public function show(Tenant $tenant, PlatformTenantRegistryService $registry): Response
    {
        return Inertia::render('Platform/Tenants/Show', [
            'tenant' => $registry->detail($tenant),
            'flash' => [
                'success' => session('success'),
                'provisioning_status' => session('provisioning_status'),
            ],
        ]);
    }

    public function edit(Tenant $tenant, PlatformTenantRegistryService $registry): Response
    {
        return Inertia::render('Platform/Tenants/Edit', [
            'tenant' => $registry->detail($tenant),
        ]);
    }

    public function update(
        PlatformUpdateTenantRequest $request,
        Tenant $tenant,
        PlatformTenantRegistryService $registry,
    ): RedirectResponse {
        $registry->updateName(
            $tenant,
            (string) $request->validated('name'),
            $request->user('platform')?->getAuthIdentifier(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('success', 'Tenant display name updated.');
    }

    public function checkHandleAvailability(
        CheckTenantHandleAvailabilityRequest $request,
        PlatformTenantRegistryService $registry,
    ): JsonResponse {
        return response()->json(
            $registry->checkHandleAvailability((string) $request->validated('handle'))
        );
    }
}
