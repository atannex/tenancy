<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\TenantPurging;
use Atannex\Tenancy\Exceptions\IllegalTransition;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\Managers\ServerManager;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Lifecycle\Exporter;
use Atannex\Tenancy\Lifecycle\Importer;
use Atannex\Tenancy\Lifecycle\Promoter;
use Atannex\Tenancy\Lifecycle\Rows;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Atannex\Tenancy\Tests\Fixtures\TenantFixtureSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class LifecycleFixtureServerManager extends ServerManager
{
    public function create(string $name, array $server): void {}

    public function delete(string $name, array $server): void {}

    public function exists(string $name, array $server): bool
    {
        return false;
    }

    public function run(string $sql, string $password): void
    {
        $this->secretStatement($this->admin(config('database.connections.testing')), $sql, $password);
    }
}

function exportZip(Tenant $tenant): string
{
    return app(Exporter::class)->export($tenant->fresh(), sys_get_temp_dir().'/atx-'.uniqid().'.zip');
}

it('only lets one of two stale copies make a transition', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Pending]);
    $stale = Tenant::find($tenant->id);
    $lifecycle = app(TenantLifecycle::class);

    $lifecycle->transition($tenant, TenantStatus::Provisioning);

    expect(fn () => $lifecycle->transition($stale, TenantStatus::Provisioning))->toThrow(IllegalTransition::class)
        ->and($stale->tenantStatus())->toBe(TenantStatus::Provisioning)
        ->and($tenant->fresh()->tenantStatus())->toBe(TenantStatus::Provisioning);
});

it('keeps passwords out of query events and failure messages', function () {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$queries) {
        $queries[] = $e->sql;
    });

    try {
        (new LifecycleFixtureServerManager)->run("ALTER NONSENSE 'hunter2-secret'", 'hunter2-secret');
        $this->fail('The statement should fail.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('hunter2-secret')
            ->and($e->getPrevious())->toBeNull();
    }

    expect(implode(' ', $queries))->not->toContain('hunter2-secret');
});

it('refuses tenant connections that point at the central database', function () {
    $path = config('tenancy.database.sqlite_path');
    File::ensureDirectoryExists($path);
    touch($path.'/central_db.sqlite');
    config([
        'database.connections.template' => ['driver' => 'sqlite', 'database' => $path.'/central_db.sqlite', 'prefix' => ''],
        'tenancy.database.template_connection' => 'template',
    ]);
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    $tenant->forceFill(['connection' => ['database' => 'central_db']])->save();

    expect(fn () => app(TenantDatabase::class)->connect($tenant))->toThrow(InvalidArgumentException::class, 'central');

    @unlink($path.'/central_db.sqlite');
});

it('refuses plain tenant deletes and announces purges first', function () {
    Event::fake([TenantPurging::class]);
    $tenant = Tenant::factory()->create();

    expect(fn () => $tenant->delete())->toThrow(IllegalTransition::class);

    Tenancy::central(fn () => DB::table('tenant_settings')->insert(['tenant_id' => $tenant->id, 'key' => 'k', 'value' => '1']));
    app(TenantLifecycle::class)->archive($tenant);
    app(TenantLifecycle::class)->purge($tenant->refresh());

    expect(Tenant::find($tenant->id))->toBeNull()
        ->and(DB::table('tenant_settings')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
    Event::assertDispatched(TenantPurging::class, fn ($e) => $e->tenant->getTenantKey() === $tenant->id);
});

it('exports and imports settings, overrides, usage and domains, but not memberships unless asked', function () {
    $tenant = Tenant::factory()->withDomain('shop.example.com')->create(['slug' => 'source']);
    Tenancy::run($tenant, fn () => Project::create(['name' => 'P', 'slug' => 'p']));
    DB::table('tenant_settings')->insert(['tenant_id' => $tenant->id, 'key' => 'theme', 'value' => '"dark"']);
    DB::table('tenant_feature_overrides')->insert(['tenant_id' => $tenant->id, 'feature' => 'beta', 'enabled' => true]);
    DB::table('tenant_usage')->insert(['tenant_id' => $tenant->id, 'metric' => 'api', 'period' => '2026-11', 'value' => 7]);
    DB::table('tenant_memberships')->insert(['id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenant->id, 'user_id' => '42', 'role' => 'owner', 'status' => 'active']);
    DB::table('tenant_invitations')->insert(['id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenant->id, 'email' => 'x@t.test', 'role' => 'member', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
    config(['tenancy.domains.auto_verify' => false]);

    $zip = exportZip($tenant);
    $archive = new ZipArchive;
    $archive->open($zip);
    $names = array_map(fn ($i) => $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
    $archive->close();

    expect($names)->toContain('package/tenant_settings.jsonl', 'package/tenant_memberships.jsonl')
        ->and(implode(' ', $names))->not->toContain('invitations');

    // Restoring a backup: the source is gone, so its auto-increment ids are free again.
    app(TenantLifecycle::class)->archive($tenant);
    app(TenantLifecycle::class)->purge($tenant->refresh());
    $copy = app(Importer::class)->import($zip, ['slug' => 'copy']);

    expect(Tenancy::run($copy, fn () => Project::pluck('name')->all()))->toBe(['P'])
        ->and(DB::table('tenant_settings')->where('tenant_id', $copy->id)->value('value'))->toBe('"dark"')
        ->and((bool) DB::table('tenant_feature_overrides')->where('tenant_id', $copy->id)->value('enabled'))->toBeTrue()
        ->and((int) DB::table('tenant_usage')->where('tenant_id', $copy->id)->value('value'))->toBe(7)
        ->and(DB::table('tenant_memberships')->where('tenant_id', $copy->id)->count())->toBe(0)
        ->and(DB::table('tenant_invitations')->where('tenant_id', $copy->id)->count())->toBe(0);

    $domain = Domain::where('tenant_id', $copy->id)->where('host', 'shop.example.com')->first();
    expect($domain)->not->toBeNull()
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->type)->toBe(DomainType::Alias);

    app(TenantLifecycle::class)->archive($copy);
    app(TenantLifecycle::class)->purge($copy->refresh());
    $withMembers = app(Importer::class)->import($zip, ['slug' => 'copy-two'], members: true);
    @unlink($zip);

    expect(DB::table('tenant_memberships')->where('tenant_id', $withMembers->id)->value('user_id'))->toBe('42');
});

it('refuses to import over ids other tenants hold on the shared tier', function () {
    $tenant = Tenant::factory()->create(['slug' => 'source']);
    Tenancy::run($tenant, fn () => Project::create(['name' => 'P', 'slug' => 'p']));
    $zip = exportZip($tenant);

    expect(fn () => app(Importer::class)->import($zip, ['slug' => 'clone']))->toThrow(RuntimeException::class, 'ULID or UUID')
        ->and(Tenant::where('slug', 'clone')->exists())->toBeFalse()
        ->and(Tenancy::central(fn () => Project::count()))->toBe(1);
    @unlink($zip);
});

it('skips imported domains another tenant has verified', function () {
    $tenant = Tenant::factory()->create(['slug' => 'source']);
    app(Domains::class)->add($tenant, 'taken.example.com', DomainType::Alias, verified: true);
    $zip = exportZip($tenant);

    $copy = app(Importer::class)->import($zip, ['slug' => 'copy']);
    @unlink($zip);

    expect(Domain::where('tenant_id', $copy->id)->where('host', 'taken.example.com')->exists())->toBeFalse();
});

it('refuses zip bombs before writing anything', function () {
    $tenant = Tenant::factory()->create(['slug' => 'source']);
    Tenancy::run($tenant, fn () => Project::create(['name' => 'P', 'slug' => 'p']));
    $zip = exportZip($tenant);

    config(['tenancy.lifecycle.import.max_entries' => 1]);
    expect(fn () => app(Importer::class)->import($zip, ['slug' => 'one']))->toThrow(RuntimeException::class, 'max_entries');

    config(['tenancy.lifecycle.import.max_entries' => 100, 'tenancy.lifecycle.import.max_bytes' => 10]);
    expect(fn () => app(Importer::class)->import($zip, ['slug' => 'two']))->toThrow(RuntimeException::class, 'max_bytes');
    @unlink($zip);

    expect(Tenant::whereIn('slug', ['one', 'two'])->exists())->toBeFalse();
});

it('reads big tables in chunks', function () {
    $tenant = Tenant::factory()->create();
    Tenancy::run($tenant, fn () => Project::insert(array_map(fn ($i) => ['name' => "p{$i}", 'slug' => "p{$i}"], range(1, Rows::CHUNK + 20))));

    $rows = iterator_to_array(Rows::read(DB::connection(), 'projects', 'tenant_id', $tenant->id), false);

    expect($rows)->toHaveCount(Rows::CHUNK + 20);
});

it('refuses to move rows into the shared tier over another tenant\'s ids', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    $paths = sys_get_temp_dir().'/atx-rows-migrations-'.uniqid();
    File::ensureDirectoryExists($paths);
    File::put($paths.'/2026_01_01_000000_projects.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('projects', function (Blueprint $table) {
            $table->id(); $table->tenant(); $table->string('name'); $table->string('slug'); $table->timestamps(); $table->tenantUnique('slug');
        });
    }
};
PHP);
    config(['tenancy.migrations.paths' => [$paths]]);

    $mover = Tenant::factory()->create(['slug' => 'mover']);
    Tenancy::run($mover, fn () => Project::create(['name' => 'M', 'slug' => 'm']));
    app(Promoter::class)->promote($mover, IsolationTier::Database, deleteSource: true);
    $mover->refresh();
    $moverId = Tenancy::run($mover, fn () => Project::value('id'));

    // Another tenant takes the mover's id on the shared tier meanwhile.
    $other = Tenant::factory()->create(['slug' => 'other']);
    Tenancy::run($other, fn () => Project::create(['name' => 'O', 'slug' => 'o']));
    Tenancy::central(fn () => Project::query()->update(['id' => $moverId]));

    expect(fn () => app(Promoter::class)->promote($mover, IsolationTier::Shared))->toThrow(RuntimeException::class, 'ULID or UUID')
        ->and($mover->fresh()->isolation)->toBe(IsolationTier::Database)
        ->and(Tenancy::central(fn () => Project::pluck('name')->all()))->toBe(['O']);

    Tenancy::reset();
    app(TenantDatabase::class)->delete($mover->fresh(), IsolationTier::Database);
    File::deleteDirectory($paths);
});

it('seeds with the tenant seeder, never the central DatabaseSeeder', function () {
    config(['tenancy.provisioning.seeder' => TenantFixtureSeeder::class]);
    $a = Tenant::factory()->create(['slug' => 'sa']);
    $b = Tenant::factory()->create(['slug' => 'sb']);

    $this->artisan('tenants:migrate', ['--seed' => true])->assertSuccessful();

    expect(Tenancy::run($a, fn () => Project::pluck('name')->all()))->toBe(['seeded'])
        ->and(Tenancy::run($b, fn () => Project::pluck('name')->all()))->toBe(['seeded']);

    $this->artisan('tenants:seed', ['--tenants' => ['sa']])->assertSuccessful();
    expect(Tenancy::run($a, fn () => Project::count()))->toBe(2);
});
