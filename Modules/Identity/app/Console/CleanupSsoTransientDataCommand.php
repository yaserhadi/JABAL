<?php

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Support\Sso\SsoSecurityAudit;

/**
 * BK-082 WS7: expire/erase stale SSO authentication transactions and handoffs (D21).
 */
class CleanupSsoTransientDataCommand extends Command
{
    protected $signature = 'identity:sso-cleanup-transient';

    protected $description = 'Expire stale SSO authentication transactions/handoffs and erase recoverable secrets';

    public function handle(AuthenticationTransactionService $transactions, SsoSecurityAudit $audit): int
    {
        $result = $transactions->expireAndEraseStale();

        $audit->record('sso.cleanup.transient', [
            'reason' => 'scheduled',
            'status' => 'ok',
            'sessions_revoked' => 0,
        ]);

        $this->info(sprintf(
            'Expired transactions=%d handoffs=%d erased=%d',
            $result['transactions_expired'],
            $result['handoffs_expired'],
            $result['secrets_erased'],
        ));

        return self::SUCCESS;
    }
}
