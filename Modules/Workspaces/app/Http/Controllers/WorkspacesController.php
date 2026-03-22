<?php

namespace Modules\Workspaces\Http\Controllers;

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
        protected WorkspaceService $workspaceService
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
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
            'workspaces' => $workspaces,
        ]);
    }

    public function create(Request $request): Response
    {
        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Create', [
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
        ]);
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceService->store($request->validated());

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray(), 201);
        }

        return redirect()
            ->route('workspaces.show', ['tenant' => tenancy()->tenant->id, 'workspace' => $workspace])
            ->with('success', 'Workspace created successfully.');
    }

    public function show(Request $request, Workspace $workspace): Response|JsonResponse
    {
        $workspace = $this->workspaceService->show($workspace);

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray());
        }

        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Show', [
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
            'workspace' => $workspace,
        ]);
    }

    public function edit(Request $request, Workspace $workspace): Response
    {
        $tenant = tenancy()->tenant;

        return Inertia::render('Workspaces/Edit', [
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
            'workspace' => $workspace,
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceService->update($workspace, $request->validated());

        if ($request->expectsJson()) {
            return ApiResponse::success($workspace->toArray());
        }

        return redirect()
            ->route('workspaces.show', ['tenant' => tenancy()->tenant->id, 'workspace' => $workspace])
            ->with('success', 'Workspace updated successfully.');
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse|JsonResponse
    {
        $this->workspaceService->destroy($workspace);

        if ($request->expectsJson()) {
            return ApiResponse::success(null, 204);
        }

        return redirect()
            ->route('workspaces.index', ['tenant' => tenancy()->tenant->id])
            ->with('success', 'Workspace deleted successfully.');
    }
}
