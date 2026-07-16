<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Support\TenantHandleValidator;

/**
 * Platform Create Tenant — selected Tenant Handle required (BK-069).
 */
class PlatformCreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('handle') && is_string($this->input('handle'))) {
            $this->merge([
                'handle' => app(TenantHandleValidator::class)->normalize($this->input('handle')),
            ]);
        }

        // Reject client-supplied UUID attempts for tenant identity
        if ($this->has('id') || $this->has('tenant_id') || $this->has('uuid')) {
            $this->merge([
                'id' => null,
                'tenant_id' => null,
                'uuid' => null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $handles = app(TenantHandleValidator::class);

        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'handle' => $handles->rules(required: true),
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
            // Isolation is system-default — reject operator override if present
            'isolation_level' => ['prohibited'],
            'id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'slug' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'isolation_level.prohibited' => 'Isolation mode is controlled by the platform default and cannot be selected.',
            'slug.prohibited' => 'Use the handle field for Tenant Handle.',
        ];
    }
}
