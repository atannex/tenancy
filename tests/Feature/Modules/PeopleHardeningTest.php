<?php

declare(strict_types=1);

use Atannex\Tenancy\Contracts\DnsResolver;
use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\DomainVerificationFailed;
use Atannex\Tenancy\Exceptions\InvitationInvalid;
use Atannex\Tenancy\Facades\Tenancy;
use Atannex\Tenancy\Identification\Hosts;
use Atannex\Tenancy\Integrations\Billing\ApplyPaidPlan;
use Atannex\Tenancy\Lifecycle\Provisioner;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Models\Impersonation;
use Atannex\Tenancy\Models\Invitation;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Impersonations;
use Atannex\Tenancy\People\Invitations;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\TenancyModulesServiceProvider;
use Atannex\Tenancy\Tests\Fixtures\FakeDns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Modules.php';

beforeEach(fn () => definePlans());

describe('impersonation', function () {
    beforeEach(function () {
        $this->tenant = planTenant('pro');
        $this->member = moduleUser('Member');
        app(Members::class)->add($this->tenant, $this->member, 'member');
        $this->impersonations = app(Impersonations::class);
        app('session')->start();
    });

    it('ends when the session is used in another tenant', function () {
        $other = planTenant('pro');
        app(Members::class)->add($other, $this->member, 'member');
        $token = $this->impersonations->issue($this->tenant, moduleUser('Support'), $this->member);
        $record = Tenancy::run($this->tenant, fn () => $this->impersonations->enter($token));

        expect(auth()->id())->toBe($this->member->id);

        Tenancy::run($other, fn () => null);

        expect(auth()->check())->toBeFalse()
            ->and(app('session')->has(Impersonations::SESSION_KEY))->toBeFalse()
            ->and($record->fresh()->ended_at)->not->toBeNull();
    });

    it('ends once the session ttl has passed', function () {
        config(['tenancy.people.impersonation.session_ttl' => 30]);
        $token = $this->impersonations->issue($this->tenant, moduleUser('Support'), $this->member);
        $record = Tenancy::run($this->tenant, fn () => $this->impersonations->enter($token));

        $this->travel(29)->minutes();
        Tenancy::run($this->tenant, fn () => null);
        expect(auth()->check())->toBeTrue();

        $this->travel(2)->minutes();
        Tenancy::run($this->tenant, fn () => null);
        expect(auth()->check())->toBeFalse()->and($record->fresh()->ended_at)->not->toBeNull();
    });

    it('logs out a login whose impersonation was ended elsewhere', function () {
        $token = $this->impersonations->issue($this->tenant, moduleUser('Support'), $this->member);
        $record = Tenancy::run($this->tenant, fn () => $this->impersonations->enter($token));
        Impersonation::query()->whereKey($record->id)->update(['ended_at' => now()]);

        Tenancy::run($this->tenant, fn () => null);

        expect(auth()->check())->toBeFalse();
    });

    it('needs a stateful guard', function () {
        config(['auth.guards.tokens' => ['driver' => 'token', 'provider' => 'users'], 'tenancy.people.impersonation.guard' => 'tokens']);
        $token = $this->impersonations->issue($this->tenant, moduleUser('Support'), $this->member);

        Tenancy::run($this->tenant, fn () => $this->impersonations->enter($token));
    })->throws(LogicException::class, 'stateful');
});

it('lets only one person accept an invitation', function () {
    Notification::fake();
    config(['tenancy.people.invitations.match_email' => false]);
    $tenant = planTenant('pro');
    $pending = app(Invitations::class)->invite($tenant, 'shared@example.test');
    $first = moduleUser('First');
    // Someone else accepted between our read and our claim.
    Invitation::retrieved(fn ($invitation) => DB::table('tenant_invitations')->where('id', $invitation->id)->whereNull('accepted_at')->update(['accepted_at' => now()]));

    expect(fn () => app(Invitations::class)->accept($pending->token, $first))->toThrow(InvitationInvalid::class)
        ->and(app(Members::class)->find($tenant, $first))->toBeNull();
});

it('keeps status, tier, connection and lifecycle dates out of mass assignment', function () {
    $tenant = Tenant::create(['name' => 'Form', 'status' => 'active', 'isolation' => 'database', 'connection' => ['host' => 'evil.internal'], 'archived_at' => now()]);
    $domain = Domain::create(['tenant_id' => $tenant->id, 'host' => 'form.test', 'verified_at' => now(), 'verification_token' => 'mine']);

    expect($tenant->fresh()->isolation)->toBe(IsolationTier::Shared)
        ->and($tenant->fresh()->connection)->toBeNull()
        ->and($tenant->fresh()->archived_at)->toBeNull()
        ->and($domain->fresh()->verified_at)->toBeNull()
        ->and($domain->fresh()->verification_token)->toBeNull();
});

it('still takes the tier and connection from Tenancy::create, but always starts pending', function () {
    Tenancy::fake();
    $tenant = app(Provisioner::class)::class;
    $real = new Provisioner(app(Atannex\Tenancy\Tenancy::class), app(TenantLifecycle::class));
    config(['tenancy.provisioning.queue' => true]);
    Queue::fake();

    $created = $real->create(['name' => 'Typed', 'isolation' => IsolationTier::Schema, 'connection' => ['host' => 'db-2.internal'], 'status' => TenantStatus::Active]);

    expect($created->fresh()->isolation)->toBe(IsolationTier::Schema)
        ->and($created->fresh()->connection)->toBe(['host' => 'db-2.internal'])
        ->and($created->fresh()->tenantStatus())->toBe(TenantStatus::Pending);
});

it('merges module config at every depth, from a published file too', function () {
    config(['tenancy-modules.people.impersonation.ttl' => 30]);
    config(['tenancy.people' => ['invitations' => ['match_email' => false], 'gate_abilities' => ['reports.*']]]);

    (new TenancyModulesServiceProvider(app()))->register();

    expect(config('tenancy.people.invitations.match_email'))->toBeFalse()
        ->and(config('tenancy.people.invitations.expires_in_days'))->toBe(7)
        ->and(config('tenancy.people.impersonation.ttl'))->toBe(30)
        ->and(config('tenancy.people.impersonation.allow_owners'))->toBeFalse()
        ->and(config('tenancy.people.gate_abilities'))->toBe(['reports.*'])
        ->and(config('tenancy.people.roles.owner'))->not->toBeEmpty();
});

it('asks the database once for many gate checks', function () {
    $tenant = planTenant('pro');
    $user = moduleUser('Admin');
    app(Members::class)->add($tenant, $user, 'admin');

    Tenancy::run($tenant, function () use ($user) {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        foreach (range(1, 5) as $i) {
            expect(Gate::forUser($user)->allows('members.invite'))->toBeTrue();
        }

        expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'tenant_memberships')))->toHaveCount(1);

        // A role change is seen at once.
        app(Members::class)->changeRole(tenant(), $user, 'viewer');
        expect(Gate::forUser($user)->allows('members.invite'))->toBeFalse();
    });
});

describe('domains', function () {
    beforeEach(function () {
        FakeDns::$records = [];
        app()->bind(DnsResolver::class, FakeDns::class);
        config(['tenancy.domains.auto_verify' => false]);
    });

    it('lets only one tenant verify a contested host', function () {
        Event::fake([DomainVerificationFailed::class]);
        $a = planTenant('pro');
        $b = planTenant('pro');
        $claimA = app(Domains::class)->add($a, 'contested.test');
        $claimB = app(Domains::class)->add($b, 'contested.test');
        FakeDns::$records['_atannex-tenancy.contested.test'] = [$claimA->verification_token, $claimB->verification_token];
        // A won a moment ago, before its rival claims were dropped.
        DB::table('tenant_domains')->where('id', $claimA->id)->update(['verified_at' => now()]);

        expect(app(Domains::class)->verify($claimB))->toBeFalse()
            ->and($claimB->fresh()?->verified_at)->toBeNull()
            ->and(Domain::query()->where('host', 'contested.test')->whereNotNull('verified_at')->count())->toBe(1);
        Event::assertDispatched(DomainVerificationFailed::class);
    });

    it('validates and normalises hosts', function () {
        expect(Hosts::normalize('Bücher.Example'))->toBe('xn--bcher-kva.example')
            ->and(Hosts::isValid('shop.example.com'))->toBeTrue()
            ->and(Hosts::isValid('localhost'))->toBeFalse()
            ->and(Hosts::isValid('bad_host.test'))->toBeFalse()
            ->and(Hosts::isValid('-x.test'))->toBeFalse();

        expect(fn () => app(Domains::class)->add(planTenant('pro'), 'not a host'))->toThrow(InvalidArgumentException::class, 'valid hostname');
    });

    it('caches unknown hosts briefly and still serves a new domain at once', function () {
        Route::middleware('tenant')->get('/where', fn () => tenant()->getTenantSlug());
        $tenant = planTenant('pro');

        $this->get('http://later.test/where')->assertNotFound();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->get('http://later.test/where')->assertNotFound();
        expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'tenant_domains')))->toBeEmpty();

        app(Domains::class)->add($tenant, 'later.test', DomainType::Primary, verified: true);
        $this->get('http://later.test/where')->assertOk()->assertSee($tenant->slug);
    });

    it('uses the configured alias redirect status', function () {
        config(['tenancy.domains.alias_redirect_status' => 302]);
        Route::middleware('tenant')->get('/where', fn () => 'here');
        $tenant = planTenant('pro');
        app(Domains::class)->add($tenant, 'main.test', DomainType::Primary, verified: true);
        app(Domains::class)->add($tenant, 'old.test', DomainType::Alias, verified: true);

        $this->get('http://old.test/where')->assertStatus(302)->assertRedirect('http://main.test/where');
    });
});

describe('paid plans', function () {
    it('ignores unknown plans and missing tenants', function () {
        $tenant = planTenant('free');
        app(ApplyPaidPlan::class)->handle((object) ['payment' => (object) ['metadata' => ['tenant' => $tenant->id, 'plan' => 'platinum']]]);
        app(ApplyPaidPlan::class)->handle((object) ['payment' => (object) ['metadata' => ['tenant' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'plan' => 'pro']]]);

        expect($tenant->fresh()->plan)->toBe('free');
    });

    it('needs the payment to cover a priced plan', function () {
        config(['tenancy.billing.prices.pro' => ['amount' => 5000, 'currency' => 'XAF']]);
        $tenant = planTenant('free');
        $pay = fn (int $amount, string $currency) => app(ApplyPaidPlan::class)->handle((object) ['payment' => (object) [
            'amount' => $amount, 'currency' => $currency, 'metadata' => ['tenant' => $tenant->id, 'plan' => 'pro'],
        ]]);

        $pay(100, 'XAF');
        $pay(5000, 'USD');
        expect($tenant->fresh()->plan)->toBe('free');

        $pay(5000, 'xaf');
        expect($tenant->fresh()->plan)->toBe('pro');
    });

    it('can require the payer to be a member', function () {
        config(['tenancy.billing.require_member' => true]);
        $tenant = planTenant('free');
        $member = moduleUser('Payer', 'payer@example.test');
        $pay = fn (string $email) => app(ApplyPaidPlan::class)->handle((object) ['payment' => (object) [
            'customer_email' => $email, 'metadata' => ['tenant' => $tenant->id, 'plan' => 'pro'],
        ]]);

        $pay('payer@example.test');
        expect($tenant->fresh()->plan)->toBe('free');

        app(Members::class)->add($tenant, $member, 'member');
        $pay('Payer@Example.test');
        expect($tenant->fresh()->plan)->toBe('pro');
    });
});
