<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    $conn = 'setup_'.$db;
    Config::set('database.connections.'.$conn, array_merge(
        config('database.connections.tenant'),
        ['database' => $db]
    ));

    if (! Schema::connection($conn)->hasTable('permissions')) {
        $runner->migrateFresh($db);
        echo "Tenant migrations ready: {$db}\n";
    } else {
        echo "Already migrated: {$db}\n";
    }
}
