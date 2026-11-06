<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Integrations\Livewire;

use Atannex\Tenancy\Http\Middleware\EnsureFeature;
use Atannex\Tenancy\Http\Middleware\EnsureMember;
use Atannex\Tenancy\Http\Middleware\EnsureTenantActive;
use Atannex\Tenancy\Http\Middleware\InitializeTenancy;
use Atannex\Tenancy\Http\Middleware\InitializeTenancyAfterSession;
use Atannex\Tenancy\Http\Middleware\InitializeTenancyIfPossible;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Livewire requests (/livewire.../update) hit one global route, so on their
 * own they carry no tenant. This makes them tenant-aware:
 *
 * - The tenancy middleware is persistent: an update re-runs the `tenant`
 *   middleware of the page the component lives on (its host and its
 *   {tenant} path parameter included), before properties are hydrated.
 * - Optionally (update_middleware), the update route itself gets extra
 *   middleware such as `tenant.optional`.
 * - Temporary uploads go to a central copy of the upload disk. Tenant disks
 *   get a per-tenant root, so a file uploaded on one request and saved in
 *   another would otherwise be looked for in the wrong folder. The upload is
 *   copied into tenant storage when the component stores it.
 */
final class LivewireTenancy
{
    public const UPLOAD_DISK = 'tenancy-livewire-tmp';

    /** @var list<class-string> */
    public const PERSISTENT = [
        InitializeTenancy::class,
        InitializeTenancyIfPossible::class,
        InitializeTenancyAfterSession::class,
        EnsureTenantActive::class,
        EnsureMember::class,
        EnsureFeature::class,
    ];

    public static function register(Repository $config): void
    {
        if (! class_exists(Livewire::class) || ! $config->get('tenancy.integrations.livewire.enabled', true)) {
            return;
        }

        if ($config->get('tenancy.integrations.livewire.persistent_middleware', true)) {
            Livewire::addPersistentMiddleware(array_values(array_filter(self::PERSISTENT, 'class_exists')));
        }

        if ($middleware = $config->get('tenancy.integrations.livewire.update_middleware')) {
            // Livewire 4 passes its (hashed) update path; Livewire 3 uses /livewire/update.
            Livewire::setUpdateRoute(fn ($handle, $path = '/livewire/update') => Route::post($path, $handle)->middleware(array_values((array) $middleware)));
        }

        if ($config->get('tenancy.integrations.livewire.central_uploads', true)) {
            self::centralUploads($config);
        }
    }

    private static function centralUploads(Repository $config): void
    {
        $disk = $config->get('livewire.temporary_file_upload.disk') ?: $config->get('filesystems.default');

        if ($disk === self::UPLOAD_DISK || ! in_array($disk, (array) $config->get('tenancy.filesystem.disks', []), true)) {
            return;
        }

        $settings = $config->get("filesystems.disks.{$disk}");

        if (! is_array($settings)) {
            return;
        }

        $config->set('filesystems.disks.'.self::UPLOAD_DISK, $settings);
        $config->set('livewire.temporary_file_upload.disk', self::UPLOAD_DISK);
    }
}
