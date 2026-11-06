<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\CacheBootstrapper;
use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Exceptions\ImpersonationDenied;
use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Lifecycle\Exporter;
use Atannex\Tenancy\Lifecycle\Importer;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Lifecycle\Promoter;
use Atannex\Tenancy\Lifecycle\Steps\CreateDatabase;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\Impersonation;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Impersonations;
use Atannex\Tenancy\People\Invitations;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Atannex\Tenancy\Tests\Fixtures\Models\User;
use Atannex\Tenancy\Tests\Fixtures\RecordingUserManager;
use Atannex\Tenancy\Tests\Fixtures\RecordTenantJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Modules.php';

// C1
it('gives every tenant its own database login, even for ulids from the same millisecond', function () {
    RecordingUserManager::$calls = [];
    config(['tenancy.database.dedicated_users' => true, 'tenancy.database.managers.sqlite' => RecordingUserManager::class]);
    $db = app(TenantDatabase::class);
    $a = Tenant::factory()->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'isolation' => IsolationTier::Database]);
    $b = Tenant::factory()->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'isolation' => IsolationTier::Database]);

    $db->create($a);
    $db->create($b);

    expect($a->fresh()->connection['username'])->not->toBe($b->fresh()->connection['username'])
        ->and(strlen($a->fresh()->connection['username']))->toBeLessThanOrEqual(32);

    $db->delete($a->fresh());
    $db->delete($b->fresh());
});

// C2
function craftedExport(string $path, array $tables, array $files = []): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => Exporter::FORMAT, 'version' => 1, 'tenant' => ['name' => 'Evil', 'slug' => 'evil', 'isolation' => 'shared'], 'tables' => array_map('count', $tables)]));
    foreach ($tables as $table => $rows) {
        $zip->addFromString("tables/{$table}.jsonl", implode("\n", array_map('json_encode', $rows)));
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

it('refuses imports that write to tables outside the tenant', function () {
    $zip = sys_get_temp_dir().'/atx-evil-'.uniqid().'.zip';
    craftedExport($zip, ['users' => [['name' => 'root', 'email' => 'root@evil.test', 'password' => 'x']]]);

    expect(fn () => app(Importer::class)->import($zip))->toThrow(RuntimeException::class, 'users');
    expect(DB::table('users')->where('email', 'root@evil.test')->exists())->toBeFalse()
        ->and(Tenant::where('slug', 'evil')->exists())->toBeFalse();
    @unlink($zip);
});

it('refuses imported files that escape the tenant folder or use other disks', function (string $name) {
    $zip = sys_get_temp_dir().'/atx-evil-'.uniqid().'.zip';
    craftedExport($zip, [], [$name => 'x']);

    expect(fn () => app(Importer::class)->import($zip))->toThrow(RuntimeException::class);
    expect(Tenant::where('slug', 'evil')->exists())->toBeFalse();
    @unlink($zip);
})->with(['files/local/../../escape.txt', 'files/local//etc/passwd', 'files/s3/x.txt']);

// I1
it('lets tenant roles answer only tenant abilities', function () {
    $tenant = Tenant::factory()->create();
    $owner = moduleUser('Owner', 'owner@t.test');
    app(Members::class)->add($tenant, $owner, 'owner');
    Gate::define('viewHorizon', fn () => false);

    Tenancy::run($tenant, function () use ($owner) {
        expect(Gate::forUser($owner)->allows('members.invite'))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('anything-central'))->toBeFalse();
    });
});

// I2
it('resolves from the session and the user through the web stack', function () {
    $tenant = Tenant::factory()->create(['slug' => 'sess']);
    $user = moduleUser('Sam', 'sam@t.test');
    app(Members::class)->add($tenant, $user, 'member');
    Route::middleware(['web', 'tenant.session:session,user'])->get('/mine', fn () => tenant()->getTenantSlug());

    $this->actingAs($user)->get('http://central.test/mine')->assertOk()->assertSee('sess');
});

it('explains that session resolvers need tenant.session', function () {
    Route::middleware(['web', 'tenant:session'])->get('/early', fn () => 'x');

    $this->withoutExceptionHandling()->get('http://central.test/early');
})->throws(InvalidArgumentException::class, 'tenant.session');

// I3
it('guards the management API with a gate by default', function () {
    expect(config('tenancy.api.middleware'))->toContain('can:tenancy.manage')->not->toContain('abilities:tenancy:manage');
});

// I4
it('never drops a database the provisioning step did not create', function () {
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    $db = app(TenantDatabase::class);
    $db->create($tenant);
    $step = app(CreateDatabase::class);

    $step->handle($tenant->fresh(), []);
    $step->rollback($tenant->fresh(), []);

    expect($db->exists($tenant->fresh()))->toBeTrue();
    $db->delete($tenant->fresh());
});

it('refuses to drop the central database', function () {
    config(['database.connections.testing.database' => 'central_db', 'database.connections.testing.driver' => 'sqlite']);
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database, 'connection' => ['database' => 'central_db']]);

    app(TenantDatabase::class)->delete($tenant);
})->throws(InvalidArgumentException::class, 'central');

it('forgets dedicated logins once the database is gone', function () {
    RecordingUserManager::$calls = [];
    config(['tenancy.database.dedicated_users' => true, 'tenancy.database.managers.sqlite' => RecordingUserManager::class]);
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    $db = app(TenantDatabase::class);

    $db->create($tenant);
    $db->delete($tenant->fresh());

    expect($tenant->fresh()->connection)->not->toHaveKey('username')->not->toHaveKey('password');
});

// I5
it('keeps users on the central database inside dedicated tenants', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    app(TenantDatabase::class)->create($tenant);
    moduleUser('Central', 'central@t.test');

    expect(Tenancy::run($tenant, fn () => User::where('email', 'central@t.test')->exists()))->toBeTrue();
    Tenancy::reset();
    app(TenantDatabase::class)->delete($tenant->fresh());
});

// I6
it('holds a tenant\'s queued jobs while its data moves', function () {
    RecordTenantJob::$seen = [];
    config(['queue.default' => 'database', 'tenancy.bootstrappers' => []]);
    $tenant = Tenant::factory()->create(['slug' => 'moving']);
    Tenancy::run($tenant, fn () => RecordTenantJob::dispatch());
    app(Maintenance::class)->down($tenant, ['hold_jobs' => true]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect(RecordTenantJob::$seen)->toBe([])->and(DB::table('jobs')->count())->toBe(1)->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('purges data left on a previous tier', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class], 'tenancy.migrations.paths' => [__DIR__.'/../Fixtures/migrations']]);
    $tenant = Tenant::factory()->create();
    Tenancy::run($tenant, fn () => Project::create(['name' => 'p', 'slug' => 'p']));

    app(Promoter::class)->promote($tenant, IsolationTier::Database);
    app(TenantLifecycle::class)->archive($tenant->fresh(), 0);
    app(TenantLifecycle::class)->purge($tenant->fresh());

    expect(Tenancy::central(fn () => Project::where('tenant_id', $tenant->id)->count()))->toBe(0)
        ->and(app(TenantDatabase::class)->exists($tenant, IsolationTier::Database))->toBeFalse();
});

it('flushes every tenant cache store on purge', function () {
    config(['tenancy.bootstrappers' => [CacheBootstrapper::class], 'tenancy.cache.stores' => ['array', 'file']]);
    $tenant = Tenant::factory()->create();
    Tenancy::run($tenant, fn () => Cache::store('file')->put('k', 'v'));

    app(TenantLifecycle::class)->archive($tenant, 0);
    app(TenantLifecycle::class)->purge($tenant->fresh());

    $resurrected = Tenant::factory()->create(['id' => $tenant->id]);
    expect(Tenancy::run($resurrected, fn () => Cache::store('file')->get('k')))->toBeNull();
});

// re-graded minors (privilege escalation)
it('lets only owners invite owners', function () {
    $tenant = Tenant::factory()->create();
    $admin = moduleUser('Admin', 'admin@t.test');
    app(Members::class)->add($tenant, $admin, 'admin');

    app(Invitations::class)->invite($tenant, 'new@t.test', 'owner', $admin);
})->throws(NotAMember::class);

it('does not change a member\'s role when they accept an invitation', function () {
    $tenant = Tenant::factory()->create();
    $owner = moduleUser('Owner', 'owner@t.test');
    app(Members::class)->add($tenant, $owner, 'owner');
    $pending = app(Invitations::class)->invite($tenant, 'owner@t.test', 'viewer', $owner);

    app(Invitations::class)->accept($pending->token, $owner);

    expect(app(Members::class)->find($tenant, $owner)->role)->toBe('owner');
});

it('redeems an impersonation token only once, atomically', function () {
    $tenant = Tenant::factory()->create();
    $admin = moduleUser('Support', 'support@central.test');
    $user = moduleUser('Member', 'm@t.test');
    app(Members::class)->add($tenant, $user, 'member');
    $token = app(Impersonations::class)->issue($tenant, $admin, $user);
    // Another request redeemed it between our read and our write.
    Impersonation::retrieved(fn ($record) => DB::table('tenant_impersonations')->where('id', $record->id)->update(['used_at' => now()]));

    Tenancy::run($tenant, fn () => app(Impersonations::class)->enter($token));
})->throws(ImpersonationDenied::class);
