<?php

declare(strict_types=1);

use Atannex\Tenancy\Contracts\CertificateIssuer;
use Atannex\Tenancy\Contracts\DnsResolver;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Events\DomainVerificationFailed;
use Atannex\Tenancy\Events\DomainVerified;
use Atannex\Tenancy\Lifecycle\Maintenance;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tests\Fixtures\FakeDns;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    FakeDns::$records = [];
    app()->bind(DnsResolver::class, FakeDns::class);
    $this->domains = app(Domains::class);
    $this->tenant = Tenant::factory()->withDomain('shop.test')->create(['slug' => 'shop']);
    Route::middleware('tenant')->get('/where', fn () => tenant()->getTenantSlug());
});

it('adds unverified domains and verifies them by DNS', function () {
    Event::fake([DomainVerified::class, DomainVerificationFailed::class]);
    $domain = $this->domains->add($this->tenant, 'www.shop-custom.test');

    expect($domain->isVerified())->toBeFalse();
    $this->get('http://www.shop-custom.test/where')->assertNotFound();
    Event::assertDispatched(DomainVerificationFailed::class);

    FakeDns::$records['_atannex-tenancy.www.shop-custom.test'] = [$domain->verification_token];
    $this->artisan('tenants:domains:verify')->assertSuccessful();

    expect($domain->fresh()->isVerified())->toBeTrue()->and($domain->fresh()->tls_status)->toBe('external');
    $this->get('http://www.shop-custom.test/where')->assertSee('shop');
    Event::assertDispatched(DomainVerified::class);
});

it('hands verified domains to the certificate issuer', function () {
    $issuer = new class implements CertificateIssuer
    {
        public array $calls = [];

        public function issue(Domain $domain): string
        {
            $this->calls[] = 'issue:'.$domain->host;

            return 'issued';
        }

        public function revoke(Domain $domain): void
        {
            $this->calls[] = 'revoke:'.$domain->host;
        }
    };
    app()->instance(CertificateIssuer::class, $issuer);
    $domains = app(Domains::class);

    $domain = $domains->add($this->tenant, 'secure.test', verified: true);
    $domains->remove($domain);

    expect($issuer->calls)->toBe(['issue:secure.test', 'revoke:secure.test'])->and(Domain::where('host', 'secure.test')->exists())->toBeFalse();
});

it('redirects aliases to the primary domain', function () {
    $this->domains->add($this->tenant, 'old-shop.test', DomainType::Alias, verified: true);

    $this->get('http://old-shop.test/where?x=1')->assertRedirect('http://shop.test/where?x=1')->assertStatus(301);
});

it('moves the primary domain', function () {
    $new = $this->domains->add($this->tenant, 'new-shop.test', DomainType::Alias, verified: true);
    $this->get('http://new-shop.test/where')->assertStatus(301);

    $this->domains->makePrimary($new);

    expect($this->tenant->primaryDomain()->host)->toBe('new-shop.test');
    $this->get('http://new-shop.test/where')->assertOk();
    $this->get('http://shop.test/where')->assertRedirect('http://new-shop.test/where');
});

it('answers the on-demand TLS ask endpoint', function () {
    $this->refreshApplicationWithTlsAsk();
    config(['tenancy.domains.tls_ask.subdomains' => true]);
    $tenant = Tenant::factory()->withDomain('tls.test')->create(['slug' => 'tls']);
    Domain::create(['tenant_id' => $tenant->id, 'host' => 'pending.test']);

    $this->get('/tenancy/tls/ask?domain=tls.test')->assertOk();
    $this->get('/tenancy/tls/ask?domain=tls.central.test')->assertOk();
    $this->get('/tenancy/tls/ask?domain=pending.test')->assertNotFound();
    $this->get('/tenancy/tls/ask?domain=evil.test')->assertNotFound();
});

it('puts one tenant into maintenance with a secret bypass', function () {
    $this->artisan('tenants:down', ['tenant' => 'shop', '--secret' => 'letmein', '--retry' => 60])->assertSuccessful();

    $this->get('http://shop.test/where')->assertStatus(503)->assertHeader('Retry-After', '60');

    $bypass = $this->get('http://shop.test/where?tenancy_secret=letmein')->assertRedirect('http://shop.test/where');
    $cookie = collect($bypass->headers->getCookies())->first();
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('http://shop.test/where')->assertOk();

    $this->artisan('tenants:up', ['tenant' => 'shop'])->assertSuccessful();
    expect(app(Maintenance::class)->isDown($this->tenant->fresh()))->toBeFalse();
});
