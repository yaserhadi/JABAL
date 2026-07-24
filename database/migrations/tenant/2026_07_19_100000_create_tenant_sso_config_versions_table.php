<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BK-082 / DEC-0024 D15+D30 foundation: immutable IdP configuration versions + active pointer.
 * BK-098: no client_secret_encrypted — credentials are reference-only via later metadata columns.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tenant_sso_config_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('config_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 32);
            $table->string('provider_label')->nullable();
            $table->text('issuer_url')->nullable();
            $table->string('client_id')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['config_id', 'version_number']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('config_id')
                ->references('id')
                ->on('tenant_sso_config')
                ->cascadeOnDelete();
        });

        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->uuid('active_version_id')->nullable()->after('scopes');
        });

        $now = now();

        foreach (DB::connection('tenant')->table('tenant_sso_config')->orderBy('id')->get() as $row) {
            $versionId = (string) Str::uuid();

            DB::connection('tenant')->table('tenant_sso_config_versions')->insert([
                'id' => $versionId,
                'tenant_id' => $row->tenant_id,
                'config_id' => $row->id,
                'version_number' => 1,
                'status' => 'active',
                'provider_label' => $row->provider_label,
                'issuer_url' => $row->issuer_url,
                'client_id' => $row->client_id,
                'redirect_uri' => $row->redirect_uri,
                'scopes' => $row->scopes,
                'activated_at' => $now,
                'superseded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection('tenant')->table('tenant_sso_config')
                ->where('id', $row->id)
                ->update(['active_version_id' => $versionId]);
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->dropColumn('active_version_id');
        });

        Schema::connection('tenant')->dropIfExists('tenant_sso_config_versions');
    }
};
