<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ties Sanctum tokens to a tenant (published with tenancy-sanctum-migrations). Tokens stay central. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.database.central_connection') ?: null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('personal_access_tokens', function (Blueprint $table): void {
            // A string fits any tenant key type (ulid, uuid, int).
            $table->string('tenant_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
