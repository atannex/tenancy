<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Published by atannex/tenancy. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Several tenants may claim a host; only one can verify it (enforced by the Domain model).
            $table->string('host')->index();
            $table->string('type', 20)->default('primary');
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable();
            $table->string('tls_status', 20)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
