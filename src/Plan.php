<?php

namespace EduLazaro\Larameter;

/**
 * A single plan, wrapping the array you defined it in.
 *
 * Holds no data of its own: it reads the config entry so that callers ask
 * $plan->allows('api_access') instead of digging into nested arrays, and so that
 * changing the shape of those arrays is one edit.
 *
 * Generic on purpose. A plan reads the same whether it was resolved from a
 * subscription, from a column, or from a default: whatever a provider had to know
 * to answer stays inside that provider.
 */
class Plan
{
    /** @var string Identifier, matching the key of your plans array. */
    public readonly string $handle;

    /** @var string Display name. Falls back to the handle. */
    public readonly string $name;

    /** @var int In whatever unit you wrote it. Never read or charged by the package. */
    public readonly int $price;

    /** @var bool False when there is no plan at all. */
    public readonly bool $exists;

    /**
     * Create a plan from its definition.
     *
     * @param string $handle
     * @param array<string, mixed> $config
     * @return void
     */
    public function __construct(string $handle, protected array $config = [])
    {
        $this->handle = $handle;
        $this->exists = $handle !== '';
        $this->name = (string) ($config['name'] ?? $handle);
        $this->price = (int) ($config['price'] ?? 0);
    }

    /**
     * Whether the plan includes a feature. Absent means no.
     *
     * @param string $feature
     * @return bool
     */
    public function allows(string $feature): bool
    {
        return (bool) ($this->config['features'][$feature] ?? false);
    }

    /**
     * Allowance in one window. Zero without credits, -1 unlimited.
     *
     * @param string $window
     * @return int
     */
    public function credits(string $window): int
    {
        $credits = $this->config['credits'] ?? [];

        if ($credits === []) {
            return 0;
        }

        return (int) ($credits[$window] ?? Plans::UNLIMITED);
    }

    /**
     * Ceiling on a resource. Absent means unlimited.
     *
     * @param string $resource
     * @return int
     */
    public function limit(string $resource): int
    {
        return (int) ($this->config['limits'][$resource] ?? Plans::UNLIMITED);
    }

    /**
     * Anything else kept in the plan definition.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * The raw plan definition.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
