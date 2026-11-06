<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Bootstrappers\FilesystemBootstrapper;
use Atannex\Tenancy\Cache\PrefixedStore;
use Atannex\Tenancy\Console\DoctorCommand;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Jobs\RunTenantCommand;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\TenancyServiceProvider;
use Atannex\Tenancy\Tests\Fixtures\Models\Note;
use Atannex\Tenancy\Tests\Fixtures\RecordTenantJob;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/** Two database-tier tenants with their databases created; dropped again by the caller. */
function dedicatedPair(string $a, string $b): array
{
    $db = app(TenantDatabase::class);
    $first = Tenant::factory()->create(['slug' => $a, 'isolation' => IsolationTier::Database]);
    $second = Tenant::factory()->create(['slug' => $b, 'isolation' => IsolationTier::Database]);
    $db->create($first);
    $db->create($second);

    return [$first->fresh(), $second->fresh()];
}

function dropTenantDatabases(Tenant ...$tenants): void
{
    Tenancy::reset();

    foreach ($tenants as $tenant) {
        app(TenantDatabase::class)->delete($tenant->fresh());
    }
}

it('pins database-backed queues, cache stores, sessions and batches to the central connection', function () {
    config([
        'queue.connections.database.connection' => null,
        'queue.batching.database' => null,
        'queue.failed.database' => null,
        'cache.stores.database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null],
        'cache.stores.array' => ['driver' => 'array'],
        'session.driver' => 'database',
        'session.connection' => null,
    ]);

    TenancyServiceProvider::pinInfrastructure(app('config'));

    expect(config('queue.connections.database.connection'))->toBe('testing')
        ->and(config('queue.batching.database'))->toBe('testing')
        ->and(config('queue.failed.database'))->toBe('testing')
        ->and(config('cache.stores.database.connection'))->toBe('testing')
        ->and(config('cache.stores.database.lock_connection'))->toBe('testing')
        ->and(config('cache.stores.array'))->toBe(['driver' => 'array'])
        ->and(config('session.connection'))->toBe('testing');
});

it('leaves a redis session connection alone and respects the opt-out', function () {
    config(['session.driver' => 'redis', 'session.connection' => null, 'queue.connections.database.connection' => null]);
    TenancyServiceProvider::pinInfrastructure(app('config'));
    expect(config('session.connection'))->toBeNull();

    config(['tenancy.database.pin_infrastructure' => false, 'queue.connections.database.connection' => null]);
    TenancyServiceProvider::pinInfrastructure(app('config'));
    expect(config('queue.connections.database.connection'))->toBeNull();
});

it('keeps jobs dispatched inside a dedicated tenant on the central queue', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class], 'queue.default' => 'database', 'queue.connections.database.connection' => null]);
    TenancyServiceProvider::pinInfrastructure(app('config'));
    [$a, $b] = dedicatedPair('qa', 'qb');

    try {
        Tenancy::run($a, fn () => dispatch(new RunTenantCommand('list')));
        Tenancy::run($b, fn () => dispatch(new RunTenantCommand('list')));
        dispatch(new RunTenantCommand('list'));

        expect(DB::connection('testing')->table('jobs')->count())->toBe(3);
    } finally {
        dropTenantDatabases($a, $b);
    }
});

it('keeps a database cache store first used inside a dedicated tenant on the central database', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class, CacheBootstrapper::class], 'tenancy.identification.cache.store' => 'array']);
    // Not pinned: the bootstrapper must still build the store against the central connection.
    config(['cache.default' => 'database', 'cache.stores.database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null]]);
    app('cache')->forgetDriver('database');
    DB::connection('testing')->getSchemaBuilder()->create('cache', function (Blueprint $t) {
        $t->string('key')->primary();
        $t->mediumText('value');
        $t->integer('expiration');
    });
    [$a, $b] = dedicatedPair('ca', 'cb');

    try {
        Tenancy::run($a, fn () => Cache::put('k', 'from A', 60));
        Tenancy::run($b, fn () => Cache::put('k', 'from B', 60));

        expect(DB::connection('testing')->table('cache')->count())->toBe(2)
            ->and(Tenancy::run($a, fn () => Cache::get('k')))->toBe('from A')
            ->and(Tenancy::run($b, fn () => Cache::get('k')))->toBe('from B');
    } finally {
        dropTenantDatabases($a, $b);
    }
});

it('gives the default Filesystem contract each tenant its own root', function () {
    config(['tenancy.bootstrappers' => [FilesystemBootstrapper::class], 'tenancy.filesystem.disks' => ['local'], 'filesystems.default' => 'local']);
    Storage::fake('local');
    $a = Tenant::factory()->create(['slug' => 'fa']);
    $b = Tenant::factory()->create(['slug' => 'fb']);

    Tenancy::run($a, fn () => app(FilesystemContract::class)->put('a.txt', 'A'));
    Tenancy::run($b, fn () => app(FilesystemContract::class)->put('b.txt', 'B'));

    expect(Tenancy::run($b, fn () => Storage::disk('local')->exists('b.txt')))->toBeTrue()
        ->and(Tenancy::run($a, fn () => Storage::disk('local')->exists('b.txt')))->toBeFalse()
        ->and(app(FilesystemContract::class)->exists('b.txt'))->toBeFalse();
});

it('runs dispatchAfterResponse jobs inside the request tenant', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    RecordTenantJob::$seen = [];
    Route::middleware('tenant:path')->get('/t/{tenant}/after', function () {
        RecordTenantJob::dispatchAfterResponse();

        return tenant()->getTenantSlug();
    });

    $this->get('http://central.test/t/acme/after')->assertOk();

    expect(RecordTenantJob::$seen)->toBe(['acme'])->and(Tenancy::current())->toBeNull();
});

it('keeps a dedicated tenant transaction open across central() and nested runs', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    [$a, $b] = dedicatedPair('ta', 'tb');
    $this->artisan('tenants:migrate', ['--tenants' => [$a->slug]])->assertSuccessful();

    try {
        Tenancy::run($a, function () use ($b) {
            try {
                DB::transaction(function () use ($b) {
                    Note::create(['body' => 'before']);
                    Tenancy::central(fn () => null);
                    Tenancy::run($b, fn () => null);
                    Note::create(['body' => 'after']);

                    throw new RuntimeException('roll back');
                });
            } catch (RuntimeException) {
            }
        });

        expect(Tenancy::run($a, fn () => Note::count()))->toBe(0);
    } finally {
        dropTenantDatabases($a, $b);
    }
});

it('runs a job held during maintenance once the tenant is back, without spending an attempt', function () {
    RecordTenantJob::$seen = [];
    config(['queue.default' => 'database', 'tenancy.bootstrappers' => [], 'tenancy.queue.hold_delay' => 0]);
    $tenant = Tenant::factory()->create(['slug' => 'moving']);
    Tenancy::run($tenant, fn () => RecordTenantJob::dispatch());
    app(Maintenance::class)->down($tenant, ['hold_jobs' => true]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();

    expect(RecordTenantJob::$seen)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(0);

    app(Maintenance::class)->up($tenant->fresh());
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();

    expect(RecordTenantJob::$seen)->toBe(['moving'])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('does not load package migrations that were published into the app', function () {
    $folder = database_path('migrations');
    File::ensureDirectoryExists($folder);
    $published = $folder.'/2030_01_01_000000_create_tenants_table.php';
    // A copy published by 1.0.0: recognised by the config key it reads.
    File::put($published, "<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function getConnection(): ?string { return config('tenancy.database.central_connection'); } public function up(): void {} };");

    try {
        $paths = TenancyServiceProvider::packageMigrations($folder);
        $names = array_map('basename', $paths);

        expect($names)->not->toContain('2026_11_06_000001_create_tenants_table.php')
            ->and($names)->toContain('2026_11_06_000002_create_tenant_domains_table.php');
    } finally {
        File::delete($published);
    }

    expect(array_map('basename', TenancyServiceProvider::packageMigrations($folder)))->toContain('2026_11_06_000001_create_tenants_table.php');
});

it('clears tagged entries when a tenant flushes its cache', function () {
    $store = new PrefixedStore(new ArrayStore, 'k1');
    $store->tags(['posts'])->put('a', 1, 60);

    $store->flush();

    expect($store->tags(['posts'])->get('a'))->toBeNull();
});

it('adds atomically through the wrapped store', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class]]);
    $tenant = Tenant::factory()->create();

    Tenancy::run($tenant, function () {
        expect(Cache::add('once', 1, 60))->toBeTrue()
            ->and(Cache::add('once', 2, 60))->toBeFalse()
            ->and(Cache::get('once'))->toBe(1);
    });

    expect(Cache::get('once'))->toBeNull();
});

it('schedules audit pruning', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->filter(fn ($c) => str_contains((string) $c, 'tenancy:prune-audit')))->toHaveCount(1);
});

it('does not prefix the queue redis connection by default', function () {
    expect(config('tenancy.redis.connections'))->toBe([]);
});

it('reports unpinned infrastructure in the doctor', function () {
    config(['tenancy.database.pin_infrastructure' => false, 'queue.connections.database.connection' => null, 'session.driver' => 'database', 'session.connection' => null]);

    expect(DoctorCommand::unpinnedInfrastructure())->toContain('queue.connections.database', 'session.connection');

    TenancyServiceProvider::pinInfrastructure(app('config'));
    expect(DoctorCommand::unpinnedInfrastructure())->toContain('queue.connections.database');

    config(['tenancy.database.pin_infrastructure' => true]);
    TenancyServiceProvider::pinInfrastructure(app('config'));
    expect(DoctorCommand::unpinnedInfrastructure())->toBe([]);
});

it('recognises published copies by their marker', function () {
    $folder = sys_get_temp_dir().'/atx-published-'.uniqid();
    File::ensureDirectoryExists($folder);
    File::put($folder.'/2030_01_01_000000_create_tenant_usage_table.php', "<?php\n/** Published by atannex/tenancy. */\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };");

    try {
        expect(array_map('basename', TenancyServiceProvider::packageMigrations($folder)))
            ->not->toContain('2026_11_06_100002_create_tenant_usage_table.php');
    } finally {
        File::deleteDirectory($folder);
    }
});

it('still loads its own migration when another package has one of the same name', function () {
    $folder = sys_get_temp_dir().'/atx-published-'.uniqid();
    File::ensureDirectoryExists($folder);
    File::put($folder.'/2025_01_01_000000_create_tenants_table.php', "<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void { Schema::create('tenants', fn (\$t) => \$t->id()); } };");

    try {
        expect(array_map('basename', TenancyServiceProvider::packageMigrations($folder)))
            ->toContain('2026_11_06_000001_create_tenants_table.php');
    } finally {
        File::deleteDirectory($folder);
    }
});
