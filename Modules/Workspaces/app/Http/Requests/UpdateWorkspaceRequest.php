<?php

namespace Modules\Workspaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Models\Workspace;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var \Modules\Workspaces\Models\Workspace|string|null $workspace */
        $workspace = $this->route('workspace');
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $tenantId = tenancy()->initialized && tenancy()->tenant
            ? tenancy()->tenant->id
            : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                $tenantId && $workspaceId
                    ? Rule::unique(Workspace::class, 'slug')
                        ->where('tenant_id', $tenantId)
                        ->ignore($workspaceId)
                    : 'unique:workspaces,slug',
            ],
        ];
    }
}
