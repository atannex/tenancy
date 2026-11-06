<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Tests\Fixtures;

use Atannex\Tenancy\Tests\Fixtures\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** A tenant seeder: one project, stamped with whichever tenant it runs in. */
final class TenantFixtureSeeder extends Seeder
{
    public function run(): void
    {
        Project::create(['name' => 'seeded', 'slug' => 'seeded-'.Str::random(6)]);
    }
}
