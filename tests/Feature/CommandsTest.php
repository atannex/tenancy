<?php

declare(strict_types=1);

use Atannex\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\File;

it('creates a tenant with domains', function () {
    $this->artisan('tenants:create', ['name' => 'Acme Corp', '--domain' => ['acme.test', 'www.acme.test']])
        ->expectsOutputToContain('acme-corp')
        ->assertSuccessful();

    $tenant = Tenant::where('slug', 'acme-corp')->firstOrFail();
    expect($tenant->domains()->orderBy('created_at')->pluck('type')->map->value->all())->toBe(['primary', 'alias']);
});

it('refuses a duplicate slug', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    $this->artisan('tenants:create', ['name' => 'Acme', '--slug' => 'acme'])->assertFailed();
});

it('lists tenants', function () {
    Tenant::factory()->create(['slug' => 'listed']);
    Tenant::factory()->suspended()->create(['slug' => 'paused']);

    $this->artisan('tenants:list')->expectsOutputToContain('listed')->expectsOutputToContain('paused')->assertSuccessful();
    $this->artisan('tenants:list', ['--status' => 'suspended'])->doesntExpectOutputToContain('listed')->assertSuccessful();
});

it('flags unprotected tenant tables in the audit', function () {
    $this->artisan('tenancy:audit')
        ->expectsOutputToContain('leaky_notes')
        ->assertFailed();
});

it('passes the audit when only protected tables remain', function () {
    config(['tenancy.audit.ignore_tables' => ['tenants', 'tenant_domains', 'leaky_notes']]);

    $this->artisan('tenancy:audit')->expectsOutputToContain('No isolation problems')->assertSuccessful();
});

it('runs the doctor', function () {
    config(['tenancy.audit.ignore_tables' => ['tenants', 'tenant_domains', 'leaky_notes']]);

    $this->artisan('tenancy:doctor')
        ->expectsOutputToContain('Central database')
        ->expectsOutputToContain('Strict mode')
        ->assertSuccessful();
});

it('installs config and the tenant migrations folder', function () {
    $this->artisan('tenancy:install')->assertSuccessful();

    expect(File::exists(config_path('tenancy.php')))->toBeTrue()
        ->and(File::isDirectory(database_path('migrations/tenant')))->toBeTrue();

    File::delete(config_path('tenancy.php'));
    File::deleteDirectory(database_path('migrations/tenant'));
    foreach (File::glob(database_path('migrations/*_create_tenant*_table.php')) as $file) {
        File::delete($file);
    }
});
