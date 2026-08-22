<?php

namespace Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSecurityPolicyRequest extends FormRequest
{
    private const ALLOWED_FIELDS = [
        'mfa_required',
        'mfa_grace_period_days',
        'password_policy',
        'session_idle_timeout',
        'authentication_policy',
        'mandatory_sso_enrollment',
        'sso_exception_closure_mode',
    ];

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
            'mfa_required' => ['sometimes', 'boolean'],
            'mfa_grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'password_policy' => ['sometimes', 'array'],
            'password_policy.min_length' => ['required_with:password_policy', 'integer', 'min:6', 'max:128'],
            'password_policy.require_uppercase' => ['required_with:password_policy', 'boolean'],
            'password_policy.require_number' => ['required_with:password_policy', 'boolean'],
            'password_policy.require_special' => ['required_with:password_policy', 'boolean'],
            'session_idle_timeout' => ['sometimes', 'integer', 'min:-1', 'max:1440'],
            'authentication_policy' => ['sometimes', 'string', 'in:password,sso,both'],
            'mandatory_sso_enrollment' => ['sometimes', 'boolean'],
            'sso_exception_closure_mode' => ['sometimes', 'string', 'in:automatic,manual'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $extra = array_diff(array_keys($this->all()), self::ALLOWED_FIELDS);
            if ($extra !== []) {
                $validator->errors()->add('_unsupported', 'Unsupported fields: '.implode(', ', $extra));
            }

            if ($this->has('session_idle_timeout')) {
                $val = $this->input('session_idle_timeout');
                if (is_int($val) && $val < 0 && $val !== -1) {
                    $validator->errors()->add('session_idle_timeout', 'Only -1 (disabled) or positive values are allowed.');
                }
            }
        });
    }
}
