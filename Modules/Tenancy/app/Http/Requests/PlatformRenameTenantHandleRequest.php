<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tenancy\Support\TenantHandleValidator;

class PlatformRenameTenantHandleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('handle')) {
            $this->merge([
                'handle' => app(TenantHandleValidator::class)->normalize((string) $this->input('handle')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handle' => app(TenantHandleValidator::class)->rules(required: true),
            'name' => ['prohibited'],
            'slug' => ['prohibited'],
            'isolation_level' => ['prohibited'],
            'status' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }
}
