<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\DB;

$runner = app(TenantLayerMigrationRunner::class);

foreach (['jabal_tenant_dedicated_a_testing', 'jabal_tenant_dedicated_b_testing'] as $db) {
    if (! str_ends_with($db, '_testing')) {
        throw new RuntimeException('Refusing: '.$db);
    }
    $exists = DB::connection('central')->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$db]);
    if (! $exists) {
        DB::connection('central')->unprepared('CREATE DATABASE "'.str_replace('"', '""', $db).'"');
        echo "Created: {$db}\n";
    }

    $runner->ensureMigrated($db);
    echo "Tenant migrations ready: {$db}\n";
}
