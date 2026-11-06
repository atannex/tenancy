<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Note;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    $this->db = app(TenantDatabase::class);
    $this->a = Tenant::factory()->create(['slug' => 'aa', 'isolation' => IsolationTier::Database]);
    $this->b = Tenant::factory()->create(['slug' => 'bb', 'isolation' => IsolationTier::Database]);
    $this->s = Tenant::factory()->create(['slug' => 'ss']);
});

afterEach(function () {
    Tenancy::reset();
    foreach ([$this->a, $this->b] as $tenant) {
        $this->db->delete($tenant->fresh());
    }
});

it('creates, finds and deletes tenant databases', function () {
    expect($this->db->exists($this->a))->toBeFalse();

    $this->db->create($this->a);

    expect($this->db->exists($this->a))->toBeTrue()
        ->and($this->a->fresh()->connection['database'])->toBe('tenant_'.strtolower($this->a->id));

    $this->db->delete($this->a);
    expect($this->db->exists($this->a))->toBeFalse();
});

it('migrates every tier and keeps data in each tenant database', function () {
    $this->db->create($this->a);
    $this->db->create($this->b);

    $this->artisan('tenants:migrate')->assertSuccessful();

    Tenancy::run($this->a, fn () => Note::create(['body' => 'alpha']));
    Tenancy::run($this->b, fn () => Note::create(['body' => 'beta']));
    Tenancy::run($this->s, fn () => Note::create(['body' => 'shared']));

    expect(Tenancy::run($this->a, fn () => Note::pluck('body')->all()))->toBe(['alpha'])
        ->and(Tenancy::run($this->b, fn () => Note::pluck('body')->all()))->toBe(['beta'])
        ->and(Tenancy::run($this->s, fn () => Note::pluck('body')->all()))->toBe(['shared'])
        ->and(Tenancy::run($this->a, fn () => Schema::hasTable('tenants')))->toBeFalse();
});

it('switches the default connection and restores it', function () {
    $this->db->create($this->a);
    $central = DB::getDefaultConnection();

    expect(Tenancy::run($this->a, fn () => DB::getDefaultConnection()))->toBe('tenant_'.strtolower($this->a->id))
        ->and(Tenancy::run($this->a, fn () => Tenant::count()))->toBe(3)
        ->and(DB::getDefaultConnection())->toBe($central)
        ->and(Tenancy::run($this->s, fn () => DB::getDefaultConnection()))->toBe($central);
});

it('reports a missing tenant database', function () {
    $this->artisan('tenants:migrate', ['--tenants' => ['aa']])->expectsOutputToContain('database missing')->assertFailed();
});

it('rolls back, refreshes and reports status', function () {
    $this->db->create($this->a);
    $this->artisan('tenants:migrate', ['--tier' => 'database', '--tenants' => ['aa']])->assertSuccessful();

    $this->artisan('tenants:status', ['--tenants' => ['aa']])->expectsOutputToContain('create_notes_table')->assertSuccessful();
    $this->artisan('tenants:rollback', ['--tenants' => ['aa']])->assertSuccessful();
    expect(Tenancy::run($this->a, fn () => Schema::hasTable('notes')))->toBeFalse();

    $this->artisan('tenants:fresh', ['--tenants' => ['aa']])->assertSuccessful();
    expect(Tenancy::run($this->a, fn () => Schema::hasTable('notes')))->toBeTrue();
});

it('refuses the schema tier on non-postgres servers', function () {
    $this->db->create(Tenant::factory()->create(['isolation' => IsolationTier::Schema]));
})->throws(InvalidArgumentException::class, 'PostgreSQL');

it('uses per-tenant server overrides', function () {
    $this->a->update(['connection' => ['host' => 'db-7.internal', 'password' => 'p']]);

    expect($this->db->config($this->a->fresh()))->toMatchArray(['host' => 'db-7.internal', 'password' => 'p']);
});
