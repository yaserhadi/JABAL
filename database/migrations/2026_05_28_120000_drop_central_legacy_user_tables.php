<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove pre-ADR-0007 Laravel default tables from central (ADR-0007).
 *
 * - users / password_reset_tokens / sessions were created on the default (central) connection
 *   before platform_users and tenant.users existed.
 * - Application users live on tenant.users; operators on central.platform_users.
 * - Sessions and tenant password resets use the tenant connection (see config/session.php, config/auth.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('platform_password_reset_tokens')) {
            Schema::connection('central')->create('platform_password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::connection('central')->hasTable('tenant_user_impersonation_tokens')) {
            Schema::connection('central')->table('tenant_user_impersonation_tokens', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::connection('central')->dropIfExists('sessions');
        Schema::connection('central')->dropIfExists('password_reset_tokens');
        Schema::connection('central')->dropIfExists('users');
    }

    public function down(): void
    {
        if (! Schema::connection('central')->hasTable('users')) {
            Schema::connection('central')->create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::connection('central')->hasTable('tenant_user_impersonation_tokens')) {
            Schema::connection('central')->table('tenant_user_impersonation_tokens', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            });
        }

        if (! Schema::connection('central')->hasTable('password_reset_tokens')) {
            Schema::connection('central')->create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::connection('central')->hasTable('sessions')) {
            Schema::connection('central')->create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->uuid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        Schema::connection('central')->dropIfExists('platform_password_reset_tokens');
    }
};
