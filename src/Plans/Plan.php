<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Plans;

/**
 * A plan: the features it unlocks and its limits. A limit is an int, null
 * (unlimited) or ['limit' => int|null, 'period' => day|month|year|all].
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $features
     * @param  array<string, int|array{limit?: int|null, period?: string}|null>  $limits
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $features = [],
        public array $limits = [],
    ) {}

    /** @param array{name?: string, features?: array<array-key, string>, limits?: array<string, mixed>} $definition */
    public static function fromArray(string $key, array $definition): self
    {
        return new self($key, (string) ($definition['name'] ?? ucfirst($key)), array_values($definition['features'] ?? []), $definition['limits'] ?? []);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function definesLimit(string $key): bool
    {
        return array_key_exists($key, $this->limits);
    }

    public function limit(string $key): ?int
    {
        $limit = $this->limits[$key] ?? null;
        $limit = is_array($limit) ? ($limit['limit'] ?? null) : $limit;

        return $limit === null ? null : (int) $limit;
    }

    public function period(string $key): ?string
    {
        $limit = $this->limits[$key] ?? null;

        return is_array($limit) ? ($limit['period'] ?? null) : null;
    }
}
