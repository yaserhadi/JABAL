<?php

namespace Modules\Tenancy\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantSettingsRequest extends FormRequest
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
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $locales = config('app.supported_locales', ['en']);

        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in($identifiers)],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in($locales)],
            'branding_logo_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'member_removal_mode' => ['sometimes', 'nullable', 'string', Rule::in(['permanent', 'reversible'])],
        ];
    }
}
