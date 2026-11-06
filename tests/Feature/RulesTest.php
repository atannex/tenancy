<?php

declare(strict_types=1);

use Atannex\Tenancy\Exceptions\NoTenantContext;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

beforeEach(function () {
    $this->a = Tenant::factory()->create();
    $this->b = Tenant::factory()->create();
    Tenancy::run($this->a, fn () => Project::create(['name' => 'A', 'slug' => 'taken']));
});

it('checks uniqueness within the current tenant only', function () {
    $passesIn = fn ($tenant) => Tenancy::run($tenant, fn () => Validator::make(
        ['slug' => 'taken'],
        ['slug' => [Rule::tenantUnique('projects', 'slug')]],
    )->passes());

    expect($passesIn($this->a))->toBeFalse()->and($passesIn($this->b))->toBeTrue();
});

it('accepts a model class and ignore()', function () {
    $project = Tenancy::run($this->a, fn () => Project::first());

    $passes = Tenancy::run($this->a, fn () => Validator::make(
        ['slug' => 'taken'],
        ['slug' => [Rule::tenantUnique(Project::class, 'slug')->ignore($project->id)]],
    )->passes());

    expect($passes)->toBeTrue();
});

it('checks existence within the current tenant only', function () {
    $id = Tenancy::run($this->a, fn () => Project::first()->id);
    $passesIn = fn ($tenant) => Tenancy::run($tenant, fn () => Validator::make(
        ['project_id' => $id],
        ['project_id' => [Rule::tenantExists('projects', 'id')]],
    )->passes());

    expect($passesIn($this->a))->toBeTrue()->and($passesIn($this->b))->toBeFalse();
});

it('needs a tenant', function () {
    Rule::tenantUnique('projects', 'slug');
})->throws(NoTenantContext::class);
