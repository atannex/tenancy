<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\NotAMember;
use Atannex\Tenancy\Integrations\Sanctum\PersonalAccessToken;
use Atannex\Tenancy\Integrations\Sanctum\SanctumTenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Tests\Fixtures\Models\ApiUser;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;

beforeEach(function () {
    $this->app->register(SanctumServiceProvider::class);
    config(['tenancy.integrations.sanctum.enabled' => true, 'auth.providers.users.model' => ApiUser::class]);
    SanctumTenancy::register(config());

    (require __DIR__.'/../../../vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
    (require __DIR__.'/../../../database/sanctum/2026_11_06_200001_add_tenant_id_to_personal_access_tokens_table.php')->up();

    $this->a = Tenant::factory()->withDomain('a.example.com')->create(['slug' => 'aa']);
    $this->b = Tenant::factory()->withDomain('b.example.com')->create(['slug' => 'bb']);
    $this->user = ApiUser::create(['name' => 'Ada', 'email' => 'ada@example.test']);
    app(Members::class)->add($this->a, $this->user, 'member');
    app(Members::class)->add($this->b, $this->user, 'member');

    Route::middleware(['tenant', 'auth:sanctum', 'tenant.token'])->get('/api/me', fn () => 'ok:'.tenant()->getTenantSlug());
    Route::middleware(['auth:sanctum', 'tenant.token'])->get('/api/central', fn () => 'central');
});

afterEach(fn () => Sanctum::usePersonalAccessTokenModel(Laravel\Sanctum\PersonalAccessToken::class));

it('uses a central token model', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(PersonalAccessToken::class)
        ->and((new PersonalAccessToken)->getConnectionName())->toBe(config('tenancy.database.central_connection'));
});

it('accepts a tenant token only in its own tenant', function () {
    $token = $this->user->createTenantToken($this->a, 'cli')->plainTextToken;

    expect(PersonalAccessToken::query()->sole()->tenant_id)->toBe($this->a->getKey());

    $this->withToken($token)->getJson('http://a.example.com/api/me')->assertOk()->assertSee('ok:aa');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('http://b.example.com/api/me')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('http://central.test/api/central')->assertForbidden();
});

it('refuses tokens tied to no tenant inside tenants unless allowed', function () {
    $token = $this->user->createToken('any')->plainTextToken;

    $this->withToken($token)->getJson('http://a.example.com/api/me')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('http://central.test/api/central')->assertOk();

    config(['tenancy.integrations.sanctum.global_tokens' => true]);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('http://a.example.com/api/me')->assertOk();
});

it('issues tenant tokens to members only', function () {
    $stranger = ApiUser::create(['name' => 'Eve', 'email' => 'eve@example.test']);

    $stranger->createTenantToken('aa', 'x');
})->throws(NotAMember::class);
