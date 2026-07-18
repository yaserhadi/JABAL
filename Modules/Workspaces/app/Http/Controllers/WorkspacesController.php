<?php

namespace Modules\Workspaces\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Api\Http\ApiResponse;
use Modules\Workspaces\Http\Requests\StoreWorkspaceRequest;
use Modules\Workspaces\Http\Requests\UpdateWorkspaceRequest;
use Modules\Workspaces\Models\Workspace;
use Modules\Workspaces\Services\WorkspaceService;

class WorkspacesController extends Controller
{
    public function __construct(
        protected WorkspaceService $workspaceService,
        protected TenantEntryUrlResolver $tenantEntryUrls,
    ) {
        $this->middleware('permission:workspace.view')->only(['index', 'show']);
        $this->middleware('permission:workspace.create')->only(['create', 'store']);
        $this->middleware('permission:workspace.update')->only(['edit', 'update']);
        $this->middleware('permission:workspace.delete')->only(['destroy']);
    }

    public function index(Request $request): Response|JsonResponse
    {
        $workspaces = $this->workspaceService->index();

        if ($request->expectsJson()) {
            return ApiResponse::success($workspaces->toArray());
        }

        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Index', [
            'tenant' => $tenant ? TenantInertiaProps::from($tenant) : null,
            'workspaces' => $workspaces,
        ]);
    }

    public function create(Request $request): Response
    {
        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Create', [
            'tenant' => $tenant ? TenantInertiaProps::from($tenant) : null,
        ]);
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceService->store($request->validated());

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray(), 201);
        }

        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('workspaces.show', $tenant, [
                'workspace' => $workspace,
            ]))
            ->with('success', 'Workspace created successfully.');
    }

    public function show(Request $request): Response|JsonResponse
    {
        $workspace = $this->workspaceService->show($this->boundWorkspace($request));

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray());
        }

        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Show', [
            'tenant' => $tenant ? TenantInertiaProps::from($tenant) : null,
            'workspace' => $workspace,
        ]);
    }

    public function edit(Request $request): Response
    {
        $workspace = $this->boundWorkspace($request);
        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Edit', [
            'tenant' => $tenant ? TenantInertiaProps::from($tenant) : null,
            'workspace' => $workspace,
        ]);
    }

    public function update(UpdateWorkspaceRequest $request): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceService->update($this->boundWorkspace($request), $request->validated());

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray());
        }

        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('workspaces.show', $tenant, [
                'workspace' => $workspace,
            ]))
            ->with('success', 'Workspace updated successfully.');
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $this->workspaceService->destroy($this->boundWorkspace($request));

        if ($request->expectsJson()) {
            return ApiResponse::success(null, 204);
        }

        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('workspaces.index', $tenant))
            ->with('success', 'Workspace deleted successfully.');
    }

    /**
     * Resolve {workspace} after SubstituteBindings.
     *
     * Web: /t/{tenant}/workspaces/{workspace}. API: /api/v1/workspaces/{workspace}.
     * Do not type-hint Workspace on the action — Controller::callAction expands
     * route parameters by position, so web {tenant} would bind into $workspace.
     * Without a type-hint, {workspace} remains a key string; resolve under tenancy scope.
     */
    private function boundWorkspace(Request $request): Workspace
    {
        $workspace = $request->route('workspace');

        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        if (is_string($workspace) || is_numeric($workspace)) {
            return Workspace::query()->whereKey($workspace)->firstOrFail();
        }

        abort(404);
    }
}
