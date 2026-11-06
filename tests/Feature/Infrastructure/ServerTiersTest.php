<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Note;
use Illuminate\Support\Facades\DB;

/*
 * Opt-in: real servers. Only databases/schemas named tenancy_test_* and logins named tenancy_t_* are touched.
 *   TENANCY_TEST_MYSQL=1 [TENANCY_TEST_MYSQL_HOST, _PORT, _USERNAME, _PASSWORD]
 *   TENANCY_TEST_PGSQL=1 [TENANCY_TEST_PGSQL_HOST, _PORT, _USERNAME, _PASSWORD, _DATABASE]
 */
function serverTemplate(string $driver): array
{
    $env = fn (string $key, string $default) => getenv('TENANCY_TEST_'.strtoupper($driver).'_'.$key) ?: $default;

    return $driver === 'mysql'
        ? ['driver' => 'mysql', 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', '3306'), 'database' => 'information_schema',
            'username' => $env('USERNAME', 'root'), 'password' => $env('PASSWORD', ''), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']
        : ['driver' => 'pgsql', 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', '5432'), 'database' => $env('DATABASE', 'postgres'),
            'username' => $env('USERNAME', 'postgres'), 'password' => $env('PASSWORD', ''), 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public'];
}

function useServer(string $driver): void
{
    config([
        'database.connections.tenancy_template' => serverTemplate($driver),
        'tenancy.database.template_connection' => 'tenancy_template',
        'tenancy.database.prefix' => 'tenancy_test_',
        'tenancy.bootstrappers' => [DatabaseBootstrapper::class],
    ]);
}

dataset('server tiers', function () {
    $sets = [];

    if (getenv('TENANCY_TEST_MYSQL')) {
        $sets['mysql database'] = ['mysql', IsolationTier::Database];
    }

    if (getenv('TENANCY_TEST_PGSQL')) {
        $sets['pgsql database'] = ['pgsql', IsolationTier::Database];
        $sets['pgsql schema'] = ['pgsql', IsolationTier::Schema];
    }

    return $sets ?: ['none configured' => [null, null]];
});

it('provisions, migrates, isolates and drops on a real server', function (?string $driver, ?IsolationTier $tier) {
    if ($driver === null) {
        $this->markTestSkipped('Set TENANCY_TEST_MYSQL=1 or TENANCY_TEST_PGSQL=1 to run against real servers.');
    }

    useServer($driver);
    $db = app(TenantDatabase::class);
    $a = Tenant::factory()->create(['isolation' => $tier]);
    $b = Tenant::factory()->create(['isolation' => $tier]);

    try {
        $db->create($a);
        $db->create($b);
        expect($db->exists($a))->toBeTrue();

        $this->artisan('tenants:migrate', ['--tenants' => [$a->slug, $b->slug]])->assertSuccessful();
        Tenancy::run($a, fn () => Note::create(['body' => 'alpha']));

        expect(Tenancy::run($a, fn () => Note::count()))->toBe(1)
            ->and(Tenancy::run($b, fn () => Note::count()))->toBe(0);
    } finally {
        Tenancy::reset();
        $db->delete($a->fresh());
        $db->delete($b->fresh());
    }

    expect($db->exists($a->fresh()))->toBeFalse();
})->with('server tiers');

it('gives tenants their own login when dedicated_users is on', function () {
    if (! getenv('TENANCY_TEST_MYSQL')) {
        $this->markTestSkipped('Set TENANCY_TEST_MYSQL=1.');
    }

    useServer('mysql');
    config(['tenancy.database.dedicated_users' => true, 'tenancy.database.user_prefix' => 'tenancy_t_']);
    $db = app(TenantDatabase::class);
    $a = Tenant::factory()->create(['isolation' => IsolationTier::Database]);

    try {
        $db->create($a);
        $connection = $a->fresh()->connection;

        expect($connection['username'])->toStartWith('tenancy_t_')->toHaveLength(32)
            ->and(Tenancy::run($a->fresh(), fn () => DB::selectOne('select current_user() as u')->u))->toStartWith($connection['username'].'@');
    } finally {
        Tenancy::reset();
        $db->delete($a->fresh());
    }

    expect(DB::connection('tenancy_template')->selectOne('select count(*) as c from mysql.user where user = ?', [$connection['username']])->c)->toBe(0);
});
