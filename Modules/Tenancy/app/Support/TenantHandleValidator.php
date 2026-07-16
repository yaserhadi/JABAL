<?php

namespace Modules\Tenancy\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\Tenant;

/**
 * Single authoritative Tenant Handle validation (BK-069).
 * Product term: Tenant Handle. Storage: tenants.slug.
 */
final class TenantHandleValidator
{
    public const CODE_INVALID = 'invalid';

    public const CODE_RESERVED = 'reserved';

    public const CODE_TAKEN = 'taken';

    public const CODE_AVAILABLE = 'available';

    /**
     * Normalize Handle: trim + lowercase.
     */
    public function normalize(string $handle): string
    {
        return strtolower(trim($handle));
    }

    /**
     * @return array{code: string, message: string, handle: string}
     */
    public function evaluate(string $rawHandle, bool $checkAvailability = true): array
    {
        $handle = $this->normalize($rawHandle);

        if ($handle === '') {
            return [
                'code' => self::CODE_INVALID,
                'message' => 'Tenant Handle is required.',
                'handle' => $handle,
            ];
        }

        $min = (int) config('tenant_handles.min_length', 3);
        $max = (int) config('tenant_handles.max_length', 63);

        if (strlen($handle) < $min || strlen($handle) > $max) {
            return [
                'code' => self::CODE_INVALID,
                'message' => "Tenant Handle must be between {$min} and {$max} characters.",
                'handle' => $handle,
            ];
        }

        // a-z 0-9 hyphen; no leading/trailing hyphen; no consecutive hyphens; no dots (FQDN)
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $handle)) {
            return [
                'code' => self::CODE_INVALID,
                'message' => 'Tenant Handle may only contain lowercase letters, numbers, and single hyphens between segments.',
                'handle' => $handle,
            ];
        }

        if (str_contains($rawHandle, '.') || str_contains($handle, '.')) {
            return [
                'code' => self::CODE_INVALID,
                'message' => 'Tenant Handle must not be a hostname or custom domain.',
                'handle' => $handle,
            ];
        }

        if ($this->isReserved($handle)) {
            return [
                'code' => self::CODE_RESERVED,
                'message' => 'This Tenant Handle is reserved.',
                'handle' => $handle,
            ];
        }

        if ($checkAvailability && $this->isTaken($handle)) {
            return [
                'code' => self::CODE_TAKEN,
                'message' => 'This Tenant Handle is not available.',
                'handle' => $handle,
            ];
        }

        return [
            'code' => self::CODE_AVAILABLE,
            'message' => 'Tenant Handle is available.',
            'handle' => $handle,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function assertValidForCreate(string $rawHandle): string
    {
        $result = $this->evaluate($rawHandle, checkAvailability: true);

        if ($result['code'] !== self::CODE_AVAILABLE) {
            throw ValidationException::withMessages([
                'handle' => [$result['message']],
                'slug' => [$result['message']],
            ]);
        }

        return $result['handle'];
    }

    public function isReserved(string $normalizedHandle): bool
    {
        $exact = array_map('strtolower', (array) config('tenant_handles.reserved', []));
        if (in_array($normalizedHandle, $exact, true)) {
            return true;
        }

        foreach ((array) config('tenant_handles.reserved_prefixes', []) as $prefix) {
            $prefix = strtolower((string) $prefix);
            if ($prefix !== '' && str_starts_with($normalizedHandle, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Includes soft-deleted rows (non-reuse policy).
     */
    public function isTaken(string $normalizedHandle): bool
    {
        return Tenant::withTrashed()
            ->where('slug', $normalizedHandle)
            ->exists();
    }

    /**
     * Laravel validation rule array for FormRequests.
     *
     * @return list<\Closure|string>
     */
    public function rules(bool $required = true): array
    {
        $rules = [
            $required ? 'required' : 'sometimes',
            'string',
            'max:'.(int) config('tenant_handles.max_length', 63),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    $fail('Tenant Handle must be a string.');

                    return;
                }

                $result = $this->evaluate($value, checkAvailability: true);
                if ($result['code'] !== self::CODE_AVAILABLE) {
                    $fail($result['message']);
                }
            },
        ];

        return $rules;
    }

    /**
     * Convenience for tests / service-layer checks without full FormRequest.
     *
     * @throws ValidationException
     */
    public function validateOrFail(string $rawHandle): string
    {
        Validator::make(
            ['handle' => $rawHandle],
            ['handle' => $this->rules(true)]
        )->validate();

        return $this->normalize($rawHandle);
    }
}
