<?php

namespace EduLazaro\Larameter;

/**
 * One plan, read from wherever your plans live.
 *
 * Holds no data of its own: it wraps the array you already wrote, so that reading a plan
 * is `$plan->allows('api_access')` instead of
 * `config('plans.' . $key . '.features.api_access') ?? false` scattered around, and so
 * that changing the shape of that array is one edit and not thirty.
 */
class Plan
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected string $key,
        protected array $config = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->key);
    }

    /** Whatever unit you wrote it in. The package neither reads nor charges it. */
    public function price(): int
    {
        return (int) ($this->config['price'] ?? 0);
    }

    public function priceId(): ?string
    {
        $key = (string) config('larameter.price_id_key', 'stripe_price_id');

        return $this->config[$key] ?? null;
    }

    /** Absent means off: a feature is something you unlock. */
    public function allows(string $feature): bool
    {
        return (bool) ($this->config['features'][$feature] ?? false);
    }

    /** Allowance in one window. 0 with no credits at all, -1 unlimited. */
    public function credits(string $window): int
    {
        $credits = $this->config['credits'] ?? [];

        if ($credits === []) {
            return 0;
        }

        return (int) ($credits[$window] ?? Plans::UNLIMITED);
    }

    /** Absent means unlimited: a restriction nobody wrote down never applied. */
    public function limit(string $resource): int
    {
        return (int) ($this->config['limits'][$resource] ?? Plans::UNLIMITED);
    }

    /** Anything else you keep in there. */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
