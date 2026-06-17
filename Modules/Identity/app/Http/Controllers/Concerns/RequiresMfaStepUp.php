<?php

namespace Modules\Identity\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Modules\Identity\Support\MfaVerificationContext;

/** Requires recent MFA step-up for sensitive controller actions. */
trait RequiresMfaStepUp
{
    protected function requireMfaStepUp(Request $request, string $purpose): void
    {
        if (MfaVerificationContext::isVerified($purpose)) {
            return;
        }

        if ($request->expectsJson()) {
            abort(403, 'MFA step-up required.');
        }

        abort(403, 'MFA step-up required for this action.');
    }
}
