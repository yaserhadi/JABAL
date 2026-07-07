<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-008: Federated identity links (issuer + subject → tenant user). Link-only; not email-unique.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tenant_user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->text('issuer');
            $table->string('subject');
            $table->string('email_at_link')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'issuer', 'subject']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('tenant_user_identities');
    }
};
