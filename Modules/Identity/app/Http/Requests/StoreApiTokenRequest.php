<?php

namespace Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StoreApiTokenRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'mfa_code' => ['sometimes', 'string', 'max:64'],
            'expires_at' => ['sometimes', 'date', 'after:now'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                ])],
            ])->status(429);
        }
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function throttleKey(): string
    {
        return str($this->ip())->lower()->append('|', (string) $this->input('email'))->toString();
    }
}
