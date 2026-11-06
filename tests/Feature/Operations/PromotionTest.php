<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Events\TenantPromoted;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Lifecycle\Promoter;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    // The dedicated tier needs the shared tables too: use the central fixture migration as the tenant migration.
    $this->paths = sys_get_temp_dir().'/atx-promo-migrations-'.uniqid();
    File::ensureDirectoryExists($this->paths);
    File::put($this->paths.'/2026_01_01_000000_projects.php', <<<'PHP'
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
    config(['tenancy.migrations.paths' => [$this->paths]]);
    $this->tenant = Tenant::factory()->create(['slug' => 'grow']);
    $this->other = Tenant::factory()->create(['slug' => 'stay']);
    Tenancy::run($this->tenant, fn () => Project::insert([['name' => 'A', 'slug' => 'a'], ['name' => 'B', 'slug' => 'b']]));
    Tenancy::run($this->other, fn () => Project::create(['name' => 'Other', 'slug' => 'o']));
});

afterEach(function () {
    Tenancy::reset();
    app(TenantDatabase::class)->delete($this->tenant->fresh(), IsolationTier::Database);
    File::deleteDirectory($this->paths);
});

it('promotes a shared tenant to its own database and back', function () {
    Event::fake([TenantPromoted::class]);

    $this->artisan('tenants:promote', ['tenant' => 'grow', '--to' => 'database', '--delete-source' => true])->assertSuccessful();
    $tenant = $this->tenant->fresh();

    expect($tenant->isolation)->toBe(IsolationTier::Database)
        ->and(Tenancy::run($tenant, fn () => Project::orderBy('slug')->pluck('name')->all()))->toBe(['A', 'B'])
        ->and(Tenancy::central(fn () => Project::pluck('name')->all()))->toBe(['Other'])
        ->and(app(Maintenance::class)->isDown($tenant))->toBeFalse();
    Event::assertDispatched(TenantPromoted::class);

    app(Promoter::class)->promote($tenant, IsolationTier::Shared, deleteSource: true);

    expect($tenant->fresh()->isolation)->toBe(IsolationTier::Shared)
        ->and(Tenancy::run($tenant->fresh(), fn () => Project::orderBy('slug')->pluck('name')->all()))->toBe(['A', 'B'])
        ->and(app(TenantDatabase::class)->exists($tenant, IsolationTier::Database))->toBeFalse();
});

it('keeps the source until asked to delete it', function () {
    app(Promoter::class)->promote($this->tenant, IsolationTier::Database);

    expect(Tenancy::central(fn () => Project::where('tenant_id', $this->tenant->id)->count()))->toBe(2);
});

it('leaves the tenant where it was when the move fails', function () {
    config(['tenancy.migrations.paths' => [sys_get_temp_dir().'/atx-no-such-dir']]);

    $this->artisan('tenants:promote', ['tenant' => 'grow', '--to' => 'database'])->assertFailed();

    expect($this->tenant->fresh()->isolation)->toBe(IsolationTier::Shared)
        ->and(app(TenantDatabase::class)->exists($this->tenant->fresh(), IsolationTier::Database))->toBeFalse()
        ->and(app(Maintenance::class)->isDown($this->tenant->fresh()))->toBeFalse();
});

it('refuses a move to the same tier', function () {
    app(Promoter::class)->promote($this->tenant, IsolationTier::Shared);
})->throws(InvalidArgumentException::class);
