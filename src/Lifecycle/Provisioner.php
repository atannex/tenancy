<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Atannex\Tenancy\Contracts\ProvisioningStep as Step;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\StepStatus;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Events\ProvisioningFailed;
use Atannex\Tenancy\Events\TenantCreated;
use Atannex\Tenancy\Events\TenantProvisioned;
use Atannex\Tenancy\Jobs\ProvisionTenant;
use Atannex\Tenancy\Models\ProvisioningStep;
use Atannex\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates tenants and runs their provisioning steps (tenancy.provisioning.steps)
 * in order, recording each one. Steps are retried; on final failure the
 * finished steps are rolled back in reverse (unless rollback_on_failure is
 * off, in which case a retry resumes at the failed step) and the tenant is
 * marked failed.
 */
class Provisioner
{
    public function __construct(private readonly Tenancy $tenancy, private readonly TenantLifecycle $lifecycle) {}

    /**
     * @param  array<string, mixed>  $attributes  tenant attributes (name, slug, isolation, plan, data …)
     * @param  array<string, mixed>  $options  for the steps: domain, verified, owner, and your own
     */
    public function create(array $attributes, array $options = []): Tenant&Model
    {
        $model = $this->tenancy->model();

        // Tier, connection overrides and trial are set on purpose here; status and
        // lifecycle dates always start fresh. Everything else goes through $fillable.
        $explicit = array_intersect_key($attributes, array_flip(['id', 'isolation', 'connection', 'trial_ends_at']));
        $fillable = array_diff_key($attributes, $explicit, array_flip(['status', 'suspended_at', 'archived_at', 'purge_after']));

        /** @var Tenant&Model $tenant */
        $tenant = $this->tenancy->central(fn () => tap((new $model)->fill($fillable)->forceFill($explicit + [
            'status' => TenantStatus::Pending,
            'isolation' => IsolationTier::from((string) config('tenancy.database.default_tier', 'shared')),
        ]))->save());

        event(new TenantCreated($tenant));
        $this->remember($tenant, $options);

        if (config('tenancy.provisioning.queue', false)) {
            ProvisionTenant::dispatch($tenant->getTenantKey());

            return $tenant;
        }

        $this->provision($tenant);

        return $tenant->refresh();
    }

    /** Run (or resume) provisioning. True when the tenant ends up active. */
    public function provision(Tenant&Model $tenant, ?array $options = null): bool
    {
        if ($options !== null) {
            $this->remember($tenant, $options);
        }

        $options = (array) (((array) ($tenant->getAttribute('data') ?? []))['provisioning'] ?? []);
        $this->lifecycle->transition($tenant, TenantStatus::Provisioning);

        $rows = $this->rowsFor($tenant);
        $tries = max(1, (int) config('tenancy.provisioning.tries', 3));

        foreach ($rows as $row) {
            if ($row->status === StepStatus::Done) {
                continue;
            }

            $step = $this->step($row->step);

            while (true) {
                $row->forceFill(['status' => StepStatus::Running, 'attempts' => $row->attempts + 1, 'started_at' => now(), 'error' => null])->save();

                try {
                    $step->handle($tenant, $options);
                    $row->forceFill(['status' => StepStatus::Done, 'finished_at' => now()])->save();

                    break;
                } catch (Throwable $e) {
                    $row->forceFill(['status' => StepStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()])->save();

                    if ($row->attempts % $tries !== 0) {
                        usleep((int) config('tenancy.provisioning.backoff_ms', 500) * 1000);

                        continue;
                    }

                    $this->fail($tenant, $rows, $row, $options, $e);

                    return false;
                }
            }
        }

        $data = (array) ($tenant->getAttribute('data') ?? []);
        unset($data['provisioning']);
        $this->lifecycle->transition($tenant, TenantStatus::Active, ['data' => $data]);
        event(new TenantProvisioned($tenant));

        return true;
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return array_values((array) config('tenancy.provisioning.steps', []));
    }

    /** @return list<ProvisioningStep> */
    private function rowsFor(Tenant&Model $tenant): array
    {
        $latest = ProvisioningStep::query()->where('tenant_id', $tenant->getTenantKey())->latest('run')->value('run');

        if ($latest !== null && ! config('tenancy.provisioning.rollback_on_failure', true)) {
            $rows = ProvisioningStep::query()->where('run', $latest)->orderBy('position')->get();

            if ($rows->contains(fn ($row) => $row->status !== StepStatus::Done)) {
                return $rows->all();
            }
        }

        $run = (string) Str::ulid();

        return array_map(fn (string $class, int $i) => ProvisioningStep::create([
            'tenant_id' => $tenant->getTenantKey(), 'run' => $run, 'position' => $i, 'step' => $class, 'status' => StepStatus::Pending,
        ]), $this->steps(), array_keys($this->steps()));
    }

    /** @param list<ProvisioningStep> $rows */
    private function fail(Tenant&Model $tenant, array $rows, ProvisioningStep $failed, array $options, Throwable $e): void
    {
        if (config('tenancy.provisioning.rollback_on_failure', true)) {
            foreach (array_reverse($rows) as $row) {
                if ($row->status !== StepStatus::Done && $row->isNot($failed)) {
                    continue;
                }

                try {
                    $this->step($row->step)->rollback($tenant, $options);
                    $row->forceFill(['status' => StepStatus::RolledBack])->save();
                } catch (Throwable $rollback) {
                    $row->forceFill(['error' => trim($row->error."\nrollback: ".$rollback->getMessage())])->save();
                }
            }
        }

        $this->lifecycle->transition($tenant, TenantStatus::Failed);
        event(new ProvisioningFailed($tenant, $failed->step, $e->getMessage()));
        report($e);
    }

    private function step(string $class): Step
    {
        $step = app($class);

        if (! $step instanceof Step) {
            throw new InvalidArgumentException("[{$class}] must implement ".Step::class.'.');
        }

        return $step;
    }

    /** @param array<string, mixed> $options */
    private function remember(Tenant&Model $tenant, array $options): void
    {
        $data = (array) ($tenant->getAttribute('data') ?? []);
        $data['provisioning'] = $options;
        $tenant->forceFill(['data' => $data])->save();
    }
}
