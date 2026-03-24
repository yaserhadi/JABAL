<?php

namespace Modules\Workspaces\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Workspaces\Models\Workspace;

/**
 * Phase 3C: Workspace CRUD service.
 *
 * Assumes tenant context (BelongsToTenant) is already set.
 */
class WorkspaceService
{
    public function index(): Collection
    {
        return Workspace::query()->latest()->get();
    }

    public function store(array $data): Workspace
    {
        $this->validateSlugUniqueness($data['slug'] ?? null);
        $workspace = new Workspace;
        $workspace->name = $data['name'];
        $workspace->slug = $data['slug'];
        $workspace->save();

        return $workspace;
    }

    public function show(Workspace $workspace): Workspace
    {
        return $workspace;
    }

    public function update(Workspace $workspace, array $data): Workspace
    {
        $newSlug = $data['slug'] ?? $workspace->slug;
        if ($newSlug !== $workspace->slug) {
            $this->validateSlugUniqueness($newSlug, $workspace->id);
        }
        $workspace->name = $data['name'] ?? $workspace->name;
        $workspace->slug = $newSlug;
        $workspace->save();

        return $workspace;
    }

    public function destroy(Workspace $workspace): void
    {
        $workspace->delete();
    }

    protected function validateSlugUniqueness(?string $slug, ?string $excludeId = null): void
    {
        if (empty($slug)) {
            return;
        }
        $query = Workspace::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The slug has already been taken within this tenant.'],
            ]);
        }
    }
}
