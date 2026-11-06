<?php

declare(strict_types=1);

use Atannex\Tenancy\Database\BlueprintMacros;
use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenancyException;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->a = Tenant::factory()->create(['slug' => 'wa']);
    $this->b = Tenant::factory()->create(['slug' => 'wb']);
    $this->pa = Tenancy::run($this->a, fn () => Project::create(['name' => 'A secret', 'slug' => 'one']));
});

function projectName(int $id): ?string
{
    return Tenancy::central(fn () => Project::find($id)?->name);
}

describe('updateOrInsert', function () {
    it('stays inside the tenant: a key held by another tenant inserts a new row', function () {
        Tenancy::run($this->b, fn () => Project::query()->updateOrInsert(['slug' => 'one'], ['name' => 'B own']));

        expect(projectName($this->pa->id))->toBe('A secret')
            ->and(Tenancy::run($this->b, fn () => Project::pluck('name')->all()))->toBe(['B own']);
    });

    it('updates the tenant\'s own row', function () {
        Tenancy::run($this->a, fn () => Project::query()->updateOrInsert(['slug' => 'one'], ['name' => 'renamed']));

        expect(projectName($this->pa->id))->toBe('renamed')
            ->and(Tenancy::central(fn () => Project::count()))->toBe(1);
    });

    it('accepts a closure for the values', function () {
        Tenancy::run($this->a, fn () => Project::query()->updateOrInsert(['slug' => 'one'], fn (bool $exists) => ['name' => $exists ? 'was there' : 'new']));

        expect(projectName($this->pa->id))->toBe('was there');
    });

    it('fails closed with no tenant in strict mode', function () {
        expect(fn () => Project::query()->updateOrInsert(['slug' => 'one'], ['name' => 'no context']))
            ->toThrow(NoTenantContext::class);

        expect(projectName($this->pa->id))->toBe('A secret');
    });

    it('refuses to move a row to another tenant', function () {
        expect(fn () => Tenancy::run($this->a, fn () => Project::query()->updateOrInsert(['slug' => 'one'], ['tenant_id' => $this->b->id])))
            ->toThrow(TenantMismatch::class);
    });
});

describe('truncate', function () {
    it('is refused inside a shared tenant', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Project::truncate()))->toThrow(TenancyException::class);

        expect(Tenancy::central(fn () => Project::count()))->toBe(1);
    });

    it('is refused with no tenant in strict mode', function () {
        expect(fn () => Project::truncate())->toThrow(NoTenantContext::class);
    });

    it('is allowed in central code', function () {
        Tenancy::central(fn () => Project::truncate());

        expect(Tenancy::central(fn () => Project::count()))->toBe(0);
    });
});

describe('insert from a query', function () {
    it('refuses insertUsing inside a tenant', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Project::query()->insertUsing(
            ['tenant_id', 'name', 'slug'],
            DB::query()->selectRaw('?, ?, ?', [$this->a->id, 'planted', 'x']),
        )))->toThrow(TenancyException::class);

        expect(Tenancy::run($this->a, fn () => Project::pluck('name')->all()))->toBe(['A secret']);
    });

    it('refuses insertOrIgnoreUsing inside a tenant', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Project::query()->insertOrIgnoreUsing(
            ['tenant_id', 'name', 'slug'],
            DB::query()->selectRaw('?, ?, ?', [$this->a->id, 'planted', 'x']),
        )))->toThrow(TenancyException::class);
    });

    it('allows insertUsing in central code', function () {
        Tenancy::central(fn () => Project::query()->insertUsing(
            ['tenant_id', 'name', 'slug'],
            DB::query()->selectRaw('?, ?, ?', [$this->b->id, 'copied', 'x']),
        ));

        expect(Tenancy::run($this->b, fn () => Project::pluck('name')->all()))->toBe(['copied']);
    });
});

describe('updateFrom and increments', function () {
    it('scopes updateFrom to the tenant', function () {
        if (! method_exists(Builder::class, 'updateFrom')) {
            $this->markTestSkipped('No updateFrom.');
        }

        // SQLite has no UPDATE ... FROM support in Laravel's grammar for joins-free queries; check the guard only.
        expect(fn () => Tenancy::run($this->b, fn () => Project::query()->updateFrom(['tenant_id' => $this->a->id])))
            ->toThrow(TenantMismatch::class);
    });

    it('refuses an increment that rewrites the tenant column', function () {
        expect(fn () => Tenancy::run($this->a, fn () => Project::query()->increment('id', 0, ['tenant_id' => $this->b->id])))
            ->toThrow(TenantMismatch::class);
    });

    it('refuses incrementing the tenant column itself', function () {
        expect(fn () => Tenancy::run($this->a, fn () => Project::query()->incrementEach(['tenant_id' => 1])))
            ->toThrow(TenantMismatch::class);
    });

    it('still increments within the tenant', function () {
        Tenancy::run($this->a, fn () => Project::query()->increment('id', 0, ['name' => 'bumped']));
        Tenancy::run($this->b, fn () => Project::query()->increment('id', 0, ['name' => 'not mine']));

        expect(projectName($this->pa->id))->toBe('bumped');
    });

    it('keeps incrementEach and decrementEach inside the tenant', function () {
        $pb = Tenancy::run($this->b, fn () => Project::create(['name' => 'B', 'slug' => 'two']));

        expect(Tenancy::run($this->b, fn () => Project::query()->incrementEach(['id' => 0], ['name' => 'not mine'])))->toBe(1)
            ->and(Tenancy::run($this->b, fn () => Project::query()->decrementEach(['id' => 0], ['name' => 'B again'])))->toBe(1)
            ->and(projectName($this->pa->id))->toBe('A secret')
            ->and(projectName($pb->id))->toBe('B again');
    });
});

describe('upsert', function () {
    it('never rewrites the tenant column of an existing row', function () {
        Tenancy::run($this->a, fn () => Project::query()->upsert(
            [['name' => 'upserted', 'slug' => 'one']],
            ['tenant_id', 'slug'],
            ['name', 'tenant_id'],
        ));

        $row = Tenancy::central(fn () => Project::find($this->pa->id));
        expect($row->name)->toBe('upserted')->and($row->tenant_id)->toBe($this->a->id);
    });

    it('still requires the tenant column in uniqueBy inside a tenant', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Project::query()->upsert([['name' => 'x', 'slug' => 'one']], ['slug'])))
            ->toThrow(TenantMismatch::class);
    });
});

describe('quiet writes', function () {
    it('blocks saveQuietly of another tenant\'s row', function () {
        expect(fn () => Tenancy::run($this->b, function () {
            $this->pa->name = 'quiet B';
            $this->pa->saveQuietly();
        }))->toThrow(TenantMismatch::class);

        expect(projectName($this->pa->id))->toBe('A secret');
    });

    it('blocks updateQuietly and deleteQuietly of another tenant\'s row', function () {
        expect(fn () => Tenancy::run($this->b, fn () => $this->pa->updateQuietly(['name' => 'quiet'])))->toThrow(TenantMismatch::class);
        expect(fn () => Tenancy::run($this->b, fn () => $this->pa->deleteQuietly()))->toThrow(TenantMismatch::class);

        expect(projectName($this->pa->id))->toBe('A secret');
    });

    it('blocks writes under Model::withoutEvents', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Model::withoutEvents(fn () => $this->pa->update(['name' => 'silent']))))
            ->toThrow(TenantMismatch::class);
    });

    it('blocks a quiet save with no tenant in strict mode', function () {
        $this->pa->name = 'nobody';

        expect(fn () => $this->pa->saveQuietly())->toThrow(NoTenantContext::class);
    });

    it('blocks a quiet change of the tenant column', function () {
        expect(fn () => Tenancy::run($this->a, function () {
            $this->pa->tenant_id = $this->b->id;
            $this->pa->saveQuietly();
        }))->toThrow(TenantMismatch::class);
    });

    it('blocks a model increment of another tenant\'s row', function () {
        expect(fn () => Tenancy::run($this->b, fn () => $this->pa->increment('id', 0)))->toThrow(TenantMismatch::class);
    });

    it('stamps createQuietly with the current tenant', function () {
        $p = Tenancy::run($this->b, fn () => Project::createQuietly(['name' => 'quiet new', 'slug' => 'q']));

        expect($p->tenant_id)->toBe($this->b->id);
    });

    it('refuses createQuietly for another tenant', function () {
        expect(fn () => Tenancy::run($this->b, fn () => Project::createQuietly(['name' => 'x', 'slug' => 'y', 'tenant_id' => $this->a->id])))
            ->toThrow(TenantMismatch::class);
    });

    it('still saves own rows quietly', function () {
        Tenancy::run($this->a, fn () => $this->pa->updateQuietly(['name' => 'own quiet']));

        expect(projectName($this->pa->id))->toBe('own quiet');
    });
});

/*
 * MySQL/MariaDB ignore uniqueBy: ON DUPLICATE KEY fires on any unique key.
 * Opt-in: TENANCY_TEST_MYSQL=1 [TENANCY_TEST_MYSQL_HOST, _PORT, _USERNAME, _PASSWORD].
 */
it('keeps an upsert from overwriting another tenant\'s row on MySQL', function () {
    if (! getenv('TENANCY_TEST_MYSQL')) {
        $this->markTestSkipped('Set TENANCY_TEST_MYSQL=1.');
    }

    $env = fn (string $key, string $default) => getenv('TENANCY_TEST_MYSQL_'.$key) ?: $default;
    $server = ['driver' => 'mysql', 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', '3306'), 'database' => '',
        'username' => $env('USERNAME', 'root'), 'password' => $env('PASSWORD', ''), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''];
    config(['database.connections.upsert_server' => $server]);
    DB::connection('upsert_server')->statement('CREATE DATABASE IF NOT EXISTS tenancy_test_upsert');
    config(['database.connections.upsert_mysql' => ['database' => 'tenancy_test_upsert'] + $server]);
    $central = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('upsert_mysql');
        DB::connection('upsert_mysql')->getSchemaBuilder()->create('projects', function ($t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
            $t->unique(['tenant_id', 'slug']);
        });
        $pa = Tenancy::run($this->a, fn () => Project::create(['name' => 'A secret', 'slug' => 'one']));

        // B names A's primary key: the duplicate must not touch A's row.
        Tenancy::run($this->b, fn () => Project::query()->upsert([['id' => $pa->id, 'name' => 'stolen by B', 'slug' => 'zzz']], ['tenant_id', 'id']));
        // A's own upsert still updates.
        Tenancy::run($this->a, fn () => Project::query()->upsert([['name' => 'A again', 'slug' => 'one']], ['tenant_id', 'slug']));

        $row = (array) DB::connection('upsert_mysql')->table('projects')->where('id', $pa->id)->first();
        expect($row['tenant_id'])->toBe($this->a->id)->and($row['name'])->toBe('A again');
    } finally {
        DB::setDefaultConnection($central);
        DB::purge('upsert_mysql');
        DB::connection('upsert_server')->statement('DROP DATABASE IF EXISTS tenancy_test_upsert');
        DB::purge('upsert_server');
    }
});

it('gives $table->tenant() the tenant model\'s key type', function () {
    expect(BlueprintMacros::keyType())->toBe('ulid');

    config(['tenancy.models.tenant' => WriteGuardsUuidTenant::class]);
    expect(BlueprintMacros::keyType())->toBe('uuid');

    config(['tenancy.models.tenant' => WriteGuardsIntTenant::class]);
    expect(BlueprintMacros::keyType())->toBe('int');
});

class WriteGuardsUuidTenant extends Model
{
    use HasUuids;
}

class WriteGuardsIntTenant extends Model {}
