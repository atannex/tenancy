<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function up(): void
    {
        Schema::create('tenant_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('feature');
            $table->boolean('enabled');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_overrides');
    }
};
