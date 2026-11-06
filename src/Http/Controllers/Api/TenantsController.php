<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Controllers\Api;

use Atannex\Tenancy\Enums\IsolationTier;
use Atannex\Tenancy\Enums\TenantStatus;
use Atannex\Tenancy\Lifecycle\TenantLifecycle;
use Atannex\Tenancy\Models\Tenant as TenantModel;
use Atannex\Tenancy\People\Members;
use Atannex\Tenancy\Plans\Plans;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Central management of tenants. Thin: every action is the same service the commands use. */
final class TenantsController
{
    public function __construct(private readonly Tenancy $tenancy, private readonly TenantLifecycle $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->tenancy->query()->orderBy('slug');
        $request->filled('status') && $query->where('status', $request->string('status'));
        $request->filled('search') && $query->where(fn ($q) => $q->where('slug', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%'));
        $page = $query->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return new JsonResponse([
            'data' => collect($page->items())->map(Presenter::tenant(...))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:63', 'regex:'.TenantModel::SLUG_PATTERN],
            'isolation' => ['nullable', Rule::enum(IsolationTier::class)],
            'plan' => ['nullable', 'string'],
            'domain' => ['nullable', 'string', 'max:253'],
            'owner' => ['nullable'],
        ]);

        $tenant = $this->tenancy->create(array_filter([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
            'plan' => $data['plan'] ?? null,
            'isolation' => isset($data['isolation']) ? IsolationTier::from($data['isolation']) : null,
        ]), array_filter(['domain' => $data['domain'] ?? null, 'owner' => $data['owner'] ?? null]));

        return new JsonResponse(['data' => Presenter::tenant($tenant)], 201);
    }

    public function show(string $tenant): JsonResponse
    {
        return new JsonResponse(['data' => Presenter::tenant($this->tenancy->findOrFail($tenant))]);
    }

    public function suspend(Request $request, string $tenant): JsonResponse
    {
        $this->lifecycle->suspend($model = $this->tenancy->findOrFail($tenant), $request->input('reason'));

        return $this->show($model->getTenantKey());
    }

    public function reactivate(string $tenant): JsonResponse
    {
        $this->lifecycle->reactivate($model = $this->tenancy->findOrFail($tenant));

        return $this->show($model->getTenantKey());
    }

    public function archive(Request $request, string $tenant): JsonResponse
    {
        $grace = $request->input('grace_days');
        $this->lifecycle->archive($model = $this->tenancy->findOrFail($tenant), $grace === null ? null : (int) $grace);

        return $this->show($model->getTenantKey());
    }

    public function restore(string $tenant): JsonResponse
    {
        $this->lifecycle->restore($model = $this->tenancy->findOrFail($tenant));

        return $this->show($model->getTenantKey());
    }

    public function plan(Request $request, string $tenant, Plans $plans): JsonResponse
    {
        $request->validate(['plan' => ['nullable', 'string']]);
        $plans->change($model = $this->tenancy->findOrFail($tenant), $request->input('plan'));

        return $this->show($model->getTenantKey());
    }

    public function members(string $tenant, Members $members): JsonResponse
    {
        $model = $this->tenancy->findOrFail($tenant);

        return new JsonResponse(['data' => $members->of($model, activeOnly: false)->map(fn ($m) => [
            'user_id' => $m->user_id,
            'role' => $m->role,
            'status' => $m->status->value,
            'joined_at' => $m->joined_at?->toIso8601String(),
        ])->values()->all()]);
    }

    public function usage(string $tenant): JsonResponse
    {
        $model = $this->tenancy->findOrFail($tenant);
        $rows = DB::connection($this->tenancy->centralConnection())->table('tenant_usage')
            ->where('tenant_id', $model->getTenantKey())->orderBy('metric')->orderByDesc('period')->get(['metric', 'period', 'value']);

        return new JsonResponse(['data' => $rows->map(fn ($r) => (array) $r)->all(), 'status' => $model->tenantStatus() === TenantStatus::Active ? 'active' : $model->tenantStatus()->value]);
    }
}
