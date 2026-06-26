<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAP-022: Pending tenant invitations (token authority on tenant layer).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tenant_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email');
            $table->uuid('invited_by_user_id');
            $table->string('token_hash', 64);
            $table->string('role', 32)->default('member');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('token_hash');
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('tenant_invitations');
    }
};
