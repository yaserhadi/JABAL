<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenancy\Exceptions\DomainCollisionException;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;

/**
 * Backfill platform-subdomain domain rows for existing Tenants (BK-073).
 *
 * Soft-deleted Tenants are included by default (deletion ≠ domain release).
 */
class BackfillPlatformDomainsCommand extends Command
{
    protected $signature = 'tenants:backfill-platform-domains
                            {--dry-run : Report only; do not write}
                            {--with-trashed : Include soft-deleted Tenants (default: true)}
                            {--without-trashed : Skip soft-deleted Tenants}';

    protected $description = 'Ensure every Tenant has a platform_subdomain domain row (BK-073 universal reservation)';

    public function handle(TenantDomainProvisioner $provisioner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeTrashed = ! (bool) $this->option('without-trashed');

        $query = $includeTrashed ? Tenant::withTrashed() : Tenant::query();
        $tenants = $query->whereNotNull('slug')->where('slug', '!=', '')->orderBy('created_at')->get();

        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            try {
                if ($dryRun) {
                    $has = $tenant->domains()->where('domain', $tenant->slug)->exists();
                    if ($has) {
                        $existing++;
                        $this->line("[skip] {$tenant->slug} already reserved");
                    } else {
                        $created++;
                        $this->line("[would-create] {$tenant->slug} → tenant {$tenant->id}");
                    }

                    continue;
                }

                $before = $tenant->domains()->where('domain', $tenant->slug)->exists();
                $provisioner->ensurePlatformSubdomain($tenant);
                if ($before) {
                    $existing++;
                    $this->line("[ok] {$tenant->slug} already reserved");
                } else {
                    $created++;
                    $this->info("[created] {$tenant->slug}");
                }
            } catch (DomainCollisionException $e) {
                $failed++;
                $this->error("[collision] {$tenant->slug}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[error] {$tenant->slug}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(
            ['metric', 'count'],
            [
                ['tenants scanned', $tenants->count()],
                ['created / would-create', $created],
                ['already present', $existing],
                ['failed', $failed],
                ['dry-run', $dryRun ? 'yes' : 'no'],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
