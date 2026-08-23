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
     * Whether this is a given plan.
     *
     * @param string $handle
     * @return bool
     */
    public function is(string $handle): bool
    {
        return $this->handle === $handle;
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
     * The plan's whole allowance for a period, before any window narrows it.
     *
     * @return int
     */
    public function credits(): int
    {
        $key = (string) config('larameter.credits_key', 'credits_monthly');

        // Through data_get, so credits_key can name a nested one. Real plan files put
        // the figure wherever they already had it, which is the point of plans_from.
        return (int) data_get($this->config, $key, 0);
    }

    /**
     * Allowance in one window: the plan's total times that window's share.
     *
     * A window with no share does not narrow anything, so it gets the whole allowance.
     * Zero when the plan grants nothing at all.
     *
     * @param string $window
     * @return int
     */
    public function creditsIn(string $window): int
    {
        $credits = $this->credits();

        if ($credits <= 0) {
            return $credits === 0 ? 0 : Plans::UNLIMITED;
        }

        $share = config('larameter.windows')[$window]['share'] ?? null;

        return $share === null ? $credits : (int) ceil($credits * (float) $share);
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
