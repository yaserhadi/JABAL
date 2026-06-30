<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Identity\Models\TenantUser;

class OnboardOrganizationTenantRequest extends FormRequest
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
            'organization_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (TenantUser::withoutGlobalScope('tenant')->where('email', $value)->exists()) {
                        $fail('A tenant user with this email already exists.');
                    }
                },
            ],
            'owner_password' => ['required', 'string', 'min:8'],
            'isolation_level' => ['sometimes', 'string', Rule::in(['shared', 'database'])],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
