<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlatformUpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['prohibited'],
            'slug' => ['prohibited'],
            'isolation_level' => ['prohibited'],
            'status' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }
}
