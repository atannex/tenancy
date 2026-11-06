<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;

/**
 * Gives each tenant its own folder on the configured disks (local roots and
 * S3/remote prefixes alike), with matching public URLs, and optionally its
 * own storage_path().
 */
final class FilesystemBootstrapper implements Bootstrapper
{
    /** @var array<string, array<string, mixed>> */
    private array $originals = [];

    private ?string $storagePath = null;

    public static function suffix(Tenant $tenant): string
    {
        return trim(str_replace('{key}', $tenant->getTenantKey(), (string) config('tenancy.filesystem.suffix', 'tenants/{key}')), '/');
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = self::suffix($tenant);

        foreach ((array) config('tenancy.filesystem.disks', []) as $disk) {
            $config = config("filesystems.disks.{$disk}");

            if (! is_array($config)) {
                continue;
            }

            $this->originals[$disk] = $config;
            $root = rtrim((string) ($config['root'] ?? ''), '/');
            $config['root'] = ltrim($root.'/'.$suffix, $root === '' ? '/' : '');

            if (! empty($config['url'])) {
                $config['url'] = rtrim((string) $config['url'], '/').'/'.$suffix;
            }

            config(["filesystems.disks.{$disk}" => $config]);
            app('filesystem')->forgetDisk($disk);
        }

        $this->forgetDefaultDisks();

        if (config('tenancy.filesystem.storage_path', false)) {
            $this->storagePath = app()->storagePath();
            app()->useStoragePath($this->storagePath.'/'.$suffix);
        }
    }

    public function revert(): void
    {
        foreach ($this->originals as $disk => $config) {
            config(["filesystems.disks.{$disk}" => $config]);
            app('filesystem')->forgetDisk($disk);
        }

        if ($this->storagePath !== null) {
            app()->useStoragePath($this->storagePath);
        }

        if ($this->originals !== []) {
            $this->forgetDefaultDisks();
        }

        $this->originals = [];
        $this->storagePath = null;
    }

    /** The Filesystem and Cloud contracts are singletons built from the default disks: rebuild them too. */
    private function forgetDefaultDisks(): void
    {
        app()->forgetInstance('filesystem.disk');
        app()->forgetInstance('filesystem.cloud');
    }
}
