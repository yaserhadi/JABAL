<?php

namespace Modules\Identity\Listeners;

use App\Http\Middleware\ConfigureApplicationRuntime;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SessionRegistryService;

class RegisterSessionOnLogin
{
    public function __construct(
        protected SessionRegistryService $sessionRegistry
    ) {}

    public function handle(Login $event): void
    {
        if (! tenancy()->initialized || ! tenancy()->tenant) {
            return;
        }

        $request = request();

        if ($request->attributes->get(ConfigureApplicationRuntime::ATTRIBUTE) !== 'tenant') {
            return;
        }

        if (! $event->user instanceof TenantUser) {
            return;
        }

        try {
            $this->sessionRegistry->register(
                $event->user,
                $request,
                $request->session()?->getId()
            );
        } catch (\Throwable $e) {
            Log::warning('SessionRegistry: failed to register session', [
                'user_id' => $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
