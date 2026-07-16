<?php

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckTenantHandleAvailabilityRequest extends FormRequest
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
            'handle' => ['required', 'string', 'max:63'],
        ];
    }
}
