<?php

declare(strict_types=1);

use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\People\Members;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Modules.php';

it('lets only active members through tenant.member, optionally by role', function () {
    $tenant = Tenant::factory()->withDomain('club.test')->create();
    $member = moduleUser('Member', 'member@club.test');
    $admin = moduleUser('Admin', 'admin@club.test');
    $stranger = moduleUser('Stranger', 'stranger@club.test');
    app(Members::class)->add($tenant, $member, 'member');
    app(Members::class)->add($tenant, $admin, 'admin');
    Route::middleware(['tenant', 'tenant.member'])->get('/inside', fn () => 'in');
    Route::middleware(['tenant', 'tenant.member:admin,owner'])->get('/admin', fn () => 'admin');

    $this->getJson('http://club.test/inside')->assertStatus(401);
    $this->actingAs($stranger)->get('http://club.test/inside')->assertForbidden();
    $this->actingAs($member)->get('http://club.test/inside')->assertOk();
    $this->actingAs($member)->get('http://club.test/admin')->assertForbidden();
    $this->actingAs($admin)->get('http://club.test/admin')->assertOk();

    app(Members::class)->suspend($tenant, $member);
    $this->actingAs($member)->get('http://club.test/inside')->assertForbidden();
});
