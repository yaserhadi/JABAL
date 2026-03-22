<?php

namespace Modules\Workspaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Models\Workspace;

class StoreWorkspaceRequest extends FormRequest
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
        $tenantId = tenancy()->initialized && tenancy()->tenant
            ? tenancy()->tenant->id
            : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                $tenantId
                    ? Rule::unique(Workspace::class, 'slug')->where('tenant_id', $tenantId)
                    : 'unique:workspaces,slug',
            ],
        ];
    }
}
