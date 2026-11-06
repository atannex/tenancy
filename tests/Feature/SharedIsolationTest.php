<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Exceptions\TenantMismatch;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->a = Tenant::factory()->withDomain('a.example.com')->create(['slug' => 'a']);
    $this->b = Tenant::factory()->withDomain('b.example.com')->create(['slug' => 'b']);
    $this->pa = Tenancy::run($this->a, fn () => Project::create(['name' => 'A1', 'slug' => 'one']));
    $this->pb = Tenancy::run($this->b, fn () => Project::create(['name' => 'B1', 'slug' => 'one']));
});

it('fills tenant_id on create', function () {
    expect($this->pa->tenant_id)->toBe($this->a->id)
        ->and($this->pa->belongsToCurrentTenant())->toBeFalse()
        ->and($this->pa->tenant->is($this->a))->toBeTrue();
});

it('only sees the current tenant rows', function () {
    expect(Tenancy::run($this->a, fn () => Project::pluck('name')->all()))->toBe(['A1'])
        ->and(Tenancy::run($this->b, fn () => Project::find($this->pa->id)))->toBeNull();
});

it('cannot update or delete another tenant rows through queries', function () {
    Tenancy::run($this->b, function () {
        expect(Project::query()->update(['name' => 'hacked']))->toBe(1)
            ->and(Project::whereKey($this->pa->id)->delete())->toBe(0);
    });

    expect(Tenancy::central(fn () => Project::find($this->pa->id)->name))->toBe('A1');
});

it('fails closed with no tenant in strict mode', function () {
    Project::count();
})->throws(NoTenantContext::class, Project::class);

it('fails closed on create with no tenant', function () {
    Project::create(['name' => 'x', 'slug' => 'x']);
})->throws(NoTenantContext::class);

it('sees everything inside central()', function () {
    expect(Tenancy::central(fn () => Project::count()))->toBe(2);
});

it('scopes explicitly with forTenant()', function () {
    expect(Tenancy::central(fn () => Project::forTenant($this->b)->pluck('name')->all()))->toBe(['B1']);
});

it('is permissive with no tenant when strict mode is off', function () {
    config(['tenancy.strict' => false]);
    expect(Project::count())->toBe(2);
});

it('refuses to move a row to another tenant', function () {
    Tenancy::central(fn () => $this->pa->update(['tenant_id' => $this->b->id]));
})->throws(TenantMismatch::class);

it('refuses to create rows for another tenant from inside a tenant', function () {
    Tenancy::run($this->a, fn () => Project::create(['name' => 'x', 'slug' => 'x', 'tenant_id' => $this->b->id]));
})->throws(TenantMismatch::class);

it('lets central code create rows for a named tenant', function () {
    $p = Tenancy::central(fn () => Project::create(['name' => 'x', 'slug' => 'x', 'tenant_id' => $this->b->id]));
    expect($p->tenant_id)->toBe($this->b->id);
});

it('requires a tenant_id when creating centrally', function () {
    Tenancy::central(fn () => Project::create(['name' => 'x', 'slug' => 'x']));
})->throws(NoTenantContext::class);

it('enforces uniqueness per tenant, not globally', function () {
    expect($this->pa->slug)->toBe($this->pb->slug);
    // In a savepoint: PostgreSQL aborts the surrounding test transaction on an error.
    DB::transaction(fn () => Tenancy::run($this->a, fn () => Project::create(['name' => 'dup', 'slug' => 'one'])));
})->throws(UniqueConstraintViolationException::class);

it('route binding cannot reach another tenant', function () {
    Route::middleware(['tenant', 'web'])->get('/projects/{project}', fn (Project $project) => $project->name);

    $this->get("http://a.example.com/projects/{$this->pa->id}")->assertOk()->assertSee('A1');
    $this->get("http://b.example.com/projects/{$this->pa->id}")->assertNotFound();
});
