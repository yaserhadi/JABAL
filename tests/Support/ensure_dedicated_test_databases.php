?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    if (! Schema::connection($conn)->hasTable('sessions')) {
        Schema::connection($conn)->create('sessions', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        echo "Sessions table ready: {$db}\n";
    }
}
