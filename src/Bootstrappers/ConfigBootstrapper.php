<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Bootstrappers;

use Atannex\Tenancy\Contracts\Bootstrapper;
use Atannex\Tenancy\Contracts\Tenant;

/**
 * Copies tenant attributes into config while the tenant is current:
 * tenancy.config_map = ['mail.from.name' => 'name', 'app.locale' => 'settings.locale'].
 * Paths are data_get() on the tenant; "settings.x" reads tenant settings.
 */
class ConfigBootstrapper implements Bootstrapper
{
    /** @var array<string, mixed> */
    private array $originals = [];

    public function bootstrap(Tenant $tenant): void
    {
        foreach ($this->map() as $key => $path) {
            $value = $this->valueFor($tenant, (string) $path);

            if ($value === null) {
                continue;
            }

            $this->originals[$key] = config($key);
            config([$key => $value]);
        }

        $this->applied(array_keys($this->originals));
    }

    public function revert(): void
    {
        $keys = array_keys($this->originals);

        foreach ($this->originals as $key => $value) {
            config([$key => $value]);
        }

        $this->originals = [];
        $this->applied($keys);
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        return (array) config('tenancy.config_map', []);
    }

    protected function valueFor(Tenant $tenant, string $path): mixed
    {
        if (str_starts_with($path, 'settings.') && method_exists($tenant, 'settings')) {
            return $tenant->settings()->get(substr($path, 9));
        }

        return data_get($tenant, $path);
    }

    /** @param list<string> $keys */
    private function applied(array $keys): void
    {
        if (in_array('app.locale', $keys, true)) {
            app()->setLocale((string) config('app.locale'));
        }

        if (array_filter($keys, fn ($key) => str_starts_with($key, 'mail.')) && app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
    }
}
