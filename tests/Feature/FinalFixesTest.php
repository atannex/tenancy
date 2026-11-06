<?php

declare(strict_types=1);

use Atannex\Tenancy\Bootstrappers\DatabaseBootstrapper;
use Atannex\Tenancy\Contracts\DnsResolver;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Identification\ResolverPipeline;
use Atannex\Tenancy\Isolation\TenantDatabase;
use Atannex\Tenancy\Jobs\RunTenantArtisan;
use Atannex\Tenancy\Lifecycle\Exporter;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Plans\Quotas;
use Atannex\Tenancy\Support\IsolationAudit;
use Atannex\Tenancy\Support\ModelFinder;
use Atannex\Tenancy\Tests\Fixtures\AuditModels\Tricky;
use Atannex\Tenancy\Tests\Fixtures\CentralListener;
use Atannex\Tenancy\Tests\Fixtures\CentralNotification;
use Atannex\Tenancy\Tests\Fixtures\FakeDns;
use Atannex\Tenancy\Tests\Fixtures\Models\PlainNote;
use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Modules.php';

// 1. Cross-tenant saves on dedicated tiers
it('saves a model back to the database it was loaded from', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    $db = app(TenantDatabase::class);
    $a = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    $b = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    $db->create($a);
    $db->create($b);
    $this->artisan('tenants:migrate', ['--tier' => 'database'])->assertSuccessful();
    Tenancy::run($a, fn () => PlainNote::create(['body' => 'alpha', 'tenant_id' => $a->id]));
    Tenancy::run($b, fn () => PlainNote::create(['body' => 'beta', 'tenant_id' => $b->id]));

    $loaded = Tenancy::run($a, fn () => PlainNote::first());
    Tenancy::run($b, fn () => $loaded->update(['body' => 'changed']));

    expect(Tenancy::run($a, fn () => PlainNote::first()->body))->toBe('changed')
        ->and(Tenancy::run($b, fn () => PlainNote::first()->body))->toBe('beta');

    Tenancy::reset();
    $db->delete($a->fresh());
    $db->delete($b->fresh());
});

// 2. #[Central] on wrapped jobs
it('keeps central queued listeners and notifications central', function () {
    config(['queue.default' => 'database', 'tenancy.bootstrappers' => []]);
    $tenant = Tenant::factory()->create();
    Event::listen('tenancy.test.ping', CentralListener::class);

    Tenancy::run($tenant, function () {
        event('tenancy.test.ping', [new stdClass]);
        Notification::route('mail', 'x@y.test')->notify(new CentralNotification);
    });

    $payloads = DB::table('jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true));
    expect($payloads)->toHaveCount(2)
        ->and($payloads->filter(fn ($p) => isset($p['tenant_id'])))->toBeEmpty();
});

// 3. Atomic owner and seat checks
it('checks seats and owners inside a locked transaction', function () {
    $tenant = Tenant::factory()->create();
    $levels = [];
    $base = DB::connection(Tenancy::centralConnection())->transactionLevel();
    app(Quotas::class)->countUsing('seats', function () use (&$levels) {
        $levels[] = DB::connection(Tenancy::centralConnection())->transactionLevel();

        return 0;
    });
    config(['tenancy.people.seat_quota' => 'seats', 'tenancy.plans.catalog.default' => ['name' => 'D', 'limits' => ['seats' => 5]], 'tenancy.plans.default' => 'default']);

    app(Members::class)->add($tenant, moduleUser('One', 'one@t.test'), 'owner');

    expect($levels)->not->toBeEmpty()->and(min($levels))->toBeGreaterThan($base);
});

// 4. Maintenance bypass cannot be forged from an export
it('keeps the maintenance bypass unforgeable from exported data', function () {
    $tenant = Tenant::factory()->withDomain('down.test')->create(['slug' => 'down']);
    Route::middleware('tenant')->get('/x', fn () => 'ok');
    app(Maintenance::class)->down($tenant, ['secret' => 'letmein']);

    $zip = app(Exporter::class)->export($tenant->fresh(), sys_get_temp_dir().'/atx-'.uniqid().'.zip');
    $archive = new ZipArchive;
    $archive->open($zip);
    $manifest = json_decode($archive->getFromName('manifest.json'), true);
    @unlink($zip);

    expect($manifest['tenant']['data'] ?? [])->not->toHaveKey('maintenance');

    $hash = $tenant->fresh()->data['maintenance']['secret'];
    $cookie = 'tenancy_bypass_'.substr(hash('sha256', $tenant->id), 0, 12);
    $this->withUnencryptedCookie($cookie, hash_hmac('sha256', $tenant->id, $hash))->get('http://down.test/x')->assertStatus(503);
});

// 5. TLS ask endpoint does not reveal slugs by default
it('does not confirm tenant subdomains on the TLS endpoint unless asked', function () {
    $this->refreshApplicationWithTlsAsk();
    Tenant::factory()->create(['slug' => 'hidden']);

    $this->get('/tenancy/tls/ask?domain=hidden.central.test')->assertNotFound();

    config(['tenancy.domains.tls_ask.subdomains' => true]);
    $this->get('/tenancy/tls/ask?domain=hidden.central.test')->assertOk();
});

// 6. Domain squatting
it('lets several tenants claim a host until one proves it', function () {
    FakeDns::$records = [];
    app()->bind(DnsResolver::class, FakeDns::class);
    $domains = app(Domains::class);
    $squatter = Tenant::factory()->create();
    $owner = Tenant::factory()->create();

    $fake = $domains->add($squatter, 'brand.test');
    $real = $domains->add($owner, 'brand.test');
    FakeDns::$records['_atannex-tenancy.brand.test'] = [$real->verification_token];

    expect($domains->verify($fake))->toBeFalse()->and($domains->verify($real))->toBeTrue()
        ->and(Domain::find($fake->id))->toBeNull()
        ->and(app(ResolverPipeline::class)->resolve(Request::create('http://brand.test/'))?->getTenantKey())->toBe($owner->id);

    expect(fn () => $domains->add($squatter, 'brand.test'))->toThrow(InvalidArgumentException::class, 'verified');
});

it('expires stale unverified claims', function () {
    $tenant = Tenant::factory()->create();
    Domain::create(['tenant_id' => $tenant->id, 'host' => 'old-claim.test']);
    $this->travel(8)->days();
    app()->bind(DnsResolver::class, FakeDns::class);

    $this->artisan('tenants:domains:verify');

    expect(Domain::where('host', 'old-claim.test')->exists())->toBeFalse();
});

// 7. Stale tenant connection in queued artisan jobs
it('refuses queued tenant migrations once the tenant is on the shared tier', function () {
    $tenant = Tenant::factory()->create();
    config(['database.connections.tenant' => config('database.connections.testing')]);

    Tenancy::run($tenant, fn () => (new RunTenantArtisan('migrate:fresh', ['--database' => 'tenant']))->handle());
})->throws(RuntimeException::class, 'shared tier');

it('forgets the tenant connection when a dedicated tenant ends', function () {
    config(['tenancy.bootstrappers' => [DatabaseBootstrapper::class]]);
    $tenant = Tenant::factory()->create(['isolation' => IsolationTier::Database]);
    app(TenantDatabase::class)->create($tenant);

    Tenancy::run($tenant, fn () => DB::connection('tenant')->getPdo());

    expect(config('database.connections.tenant'))->toBeNull();
    app(TenantDatabase::class)->delete($tenant->fresh());
});

// 8a. Nested central domains
it('resolves subdomains of nested central domains', function () {
    config(['tenancy.identification.central_domains' => ['example.test', 'app.example.test']]);
    Tenant::factory()->create(['slug' => 'acme']);

    expect(app(ResolverPipeline::class)->resolve(Request::create('http://acme.app.example.test/'), ['subdomain'])?->getTenantSlug())->toBe('acme');
});

// 8b. No internals in production error responses
it('hides class names and slugs from production JSON errors', function () {
    config(['app.debug' => false]);
    Tenant::factory()->withDomain('quiet.test')->create(['slug' => 'secret-slug', 'status' => TenantStatus::Suspended]);
    Route::middleware('tenant')->get('/q', fn () => 'x');
    Route::get('/strict', fn () => Project::count());

    $this->getJson('http://quiet.test/q')->assertStatus(423)->assertJsonMissingPath('exception')
        ->assertDontSee('secret-slug');
    $this->getJson('http://central.test/strict')->assertStatus(500)->assertDontSee('Project');
});

it('keeps detailed messages when debugging', function () {
    config(['app.debug' => true]);
    Tenant::factory()->withDomain('loud.test')->create(['slug' => 'loud-slug', 'status' => TenantStatus::Suspended]);
    Route::middleware('tenant')->get('/q', fn () => 'x');

    $this->getJson('http://loud.test/q')->assertStatus(423)->assertSee('loud-slug');
});

// 8c. Audit gaps
it('finds the real class in a model file', function () {
    expect(ModelFinder::in([__DIR__.'/../Fixtures/AuditModels']))->toContain(Tricky::class);
});

it('audits each model by its own tenant column and flags tables missing it', function () {
    Schema::create('tricky_rows', function ($t) {
        $t->id();
        $t->string('workspace_id')->index();
    });
    Schema::create('columnless_rows', fn ($t) => $t->id());
    config(['tenancy.audit.paths' => [__DIR__.'/../Fixtures/AuditModels']]);

    $problems = implode("\n", IsolationAudit::run());

    expect($problems)->not->toContain('tricky_rows')
        ->and($problems)->toContain('columnless_rows');
});

it('audits through table prefixes', function () {
    config(['database.connections.testing.prefix' => 'app_']);
    DB::purge('testing');
    Schema::create('tricky_rows', function ($t) {
        $t->id();
        $t->string('workspace_id');
    });
    config(['tenancy.audit.paths' => [__DIR__.'/../Fixtures/AuditModels']]);

    expect(implode("\n", IsolationAudit::run()))->toContain('tricky_rows')->toContain('no index');
});
