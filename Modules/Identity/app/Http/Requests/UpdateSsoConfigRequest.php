<?php

namespace Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Identity\Support\Sso\SsoIssuerUrlValidator;

class UpdateSsoConfigRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'enabled',
        'provider_label',
        'issuer_url',
        'client_id',
        'client_secret',
        'redirect_uri',
        'scopes',
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
            'enabled' => ['sometimes', 'boolean'],
            'provider_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'issuer_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'redirect_uri' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedForService(): array
    {
        $validated = $this->safe()->only(self::ALLOWED_FIELDS);

        if (
            ! array_key_exists('scopes', $validated)
            && $this->shouldApplyDefaultScopes()
        ) {
            $validated['scopes'] = config('identity.sso.default_scopes', ['openid', 'profile', 'email']);
        }

        return $validated;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $extra = array_diff(array_keys($this->all()), self::ALLOWED_FIELDS);
            if ($extra !== []) {
                $validator->errors()->add('_unsupported', 'Unsupported fields: '.implode(', ', $extra));
            }

            $tenant = tenancy()->tenant;
            if (! $tenant) {
                return;
            }

            $service = app(SsoConfigService::class);
            $current = $service->getForTenant($tenant);

            $requestedEnabled = $this->has('enabled') ? $this->boolean('enabled') : null;
            $willBeEnabled = $requestedEnabled ?? (bool) ($current['enabled'] ?? false);

            if ($requestedEnabled === true && ($current['disabled_by_entitlement'] ?? false)) {
                $validator->errors()->add('enabled', 'SSO cannot be enabled while disabled by entitlement.');
            }

            if ($requestedEnabled === true && ! app(SecurityFeatureGate::class)->isSsoAvailable($tenant)) {
                $validator->errors()->add('enabled', 'SSO is not available for this tenant plan.');
            }

            if ($willBeEnabled) {
                $issuer = $this->filled('issuer_url')
                    ? (string) $this->input('issuer_url')
                    : (string) ($current['issuer_url'] ?? '');

                $clientId = $this->filled('client_id')
                    ? (string) $this->input('client_id')
                    : (string) ($current['client_id'] ?? '');

                $hasSecret = (bool) ($current['has_client_secret'] ?? false);
                if ($this->filled('client_secret')) {
                    $hasSecret = true;
                }

                if ($issuer === '') {
                    $validator->errors()->add('issuer_url', 'Issuer URL is required when SSO is enabled.');
                }

                if ($clientId === '') {
                    $validator->errors()->add('client_id', 'Client ID is required when SSO is enabled.');
                }

                if (! $hasSecret) {
                    $validator->errors()->add('client_secret', 'Client secret is required when SSO is enabled.');
                }

                if ($issuer !== '' && ($this->has('issuer_url') || $requestedEnabled === true)) {
                    try {
                        app(SsoIssuerUrlValidator::class)->validateConfiguredIssuer($issuer);
                    } catch (SsoSecurityException $exception) {
                        $validator->errors()->add('issuer_url', $exception->getMessage());
                    }
                }
            }

            if ($this->has('scopes')) {
                $scopes = $this->input('scopes');
                if (! is_array($scopes) || ! in_array('openid', $scopes, true)) {
                    $validator->errors()->add('scopes', 'Scopes must include openid.');
                }
            }
        });
    }

    protected function shouldApplyDefaultScopes(): bool
    {
        if (! $this->has('enabled')) {
            return false;
        }

        return $this->boolean('enabled');
    }
}
