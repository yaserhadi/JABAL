<?php

namespace Modules\Identity\Listeners;

use App\Http\Middleware\ConfigureApplicationRuntime;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\SessionRegistryService;

class DeregisterSessionOnLogout
{
    public function __construct(
        protected SessionRegistryService $sessionRegistry
    ) {}

    public function handle(Logout $event): void
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

        $sessionId = $request->session()?->getId();

        if (! $sessionId) {
            return;
        }

        try {
            $record = UserSession::where('session_id', $sessionId)
                ->where('user_id', $event->user->id)
                ->whereNull('revoked_at')
                ->first();

            if (! $record) {
                return;
            }

            $this->sessionRegistry->revoke($record->id);
        } catch (\Throwable $e) {
            Log::warning('SessionRegistry: failed to deregister session', [
                'user_id' => $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
