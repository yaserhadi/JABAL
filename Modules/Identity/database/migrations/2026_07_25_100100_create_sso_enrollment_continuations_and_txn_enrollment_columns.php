<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-099 Scenario B: enrollment continuation store + Authentication Transaction enrollment columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('sso_authentication_transactions', function (Blueprint $table) {
            $table->uuid('enrollment_invitation_id')->nullable()->after('purpose');
            $table->uuid('intended_user_id')->nullable()->after('enrollment_invitation_id');
            $table->index('enrollment_invitation_id');
            $table->index('intended_user_id');
        });

        Schema::connection('central')->create('sso_enrollment_continuations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->unique();
            $table->uuid('tenant_id')->index();
            $table->uuid('invitation_id')->index();
            $table->uuid('intended_user_id')->index();
            $table->uuid('idp_configuration_version_id');
            $table->string('destination_host');
            $table->text('issuer_encrypted');
            $table->text('subject_encrypted');
            $table->uuid('lookup')->unique();
            $table->string('secret_hash', 64);
            $table->string('browser_binding_secret_hash', 64)->nullable();
            $table->string('status', 32)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('id')
                ->on('sso_authentication_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sso_enrollment_continuations');

        Schema::connection('central')->table('sso_authentication_transactions', function (Blueprint $table) {
            $table->dropIndex(['enrollment_invitation_id']);
            $table->dropIndex(['intended_user_id']);
            $table->dropColumn(['enrollment_invitation_id', 'intended_user_id']);
        });
    }
};
