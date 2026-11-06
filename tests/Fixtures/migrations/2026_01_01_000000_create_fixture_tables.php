<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->tenant();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->tenantUnique('slug');
        });

        Schema::create('leaky_notes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('body');
        });
    }
};
