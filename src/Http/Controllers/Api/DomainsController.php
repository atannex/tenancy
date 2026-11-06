<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Http\Controllers\Api;

use Atannex\Tenancy\Domains\Domains;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Models\Domain;
use Atannex\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DomainsController
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Domains $domains) {}

    public function index(string $tenant): JsonResponse
    {
        return new JsonResponse(['data' => $this->tenancy->findOrFail($tenant)->domains()->get()->map(Presenter::domain(...))->all()]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', Rule::unique(Domain::class, 'host')],
            'type' => ['nullable', Rule::enum(DomainType::class)],
        ]);

        $domain = $this->domains->add($this->tenancy->findOrFail($tenant), $data['host'], DomainType::from($data['type'] ?? 'alias'));

        return new JsonResponse(['data' => Presenter::domain($domain) + ['verification' => $this->domains->record($domain)]], 201);
    }

    public function verify(string $domain): JsonResponse
    {
        $model = Domain::query()->findOrFail($domain);
        $verified = $this->domains->verify($model);

        return new JsonResponse(['data' => Presenter::domain($model->refresh()) + ['verification' => $this->domains->record($model)]], $verified ? 200 : 422);
    }

    public function primary(string $domain): JsonResponse
    {
        $this->domains->makePrimary($model = Domain::query()->findOrFail($domain));

        return new JsonResponse(['data' => Presenter::domain($model->refresh())]);
    }

    public function destroy(string $domain): JsonResponse
    {
        $this->domains->remove(Domain::query()->findOrFail($domain));

        return new JsonResponse(null, 204);
    }
}
