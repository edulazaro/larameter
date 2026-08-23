<?php

namespace EduLazaro\Larameter;

/**
 * The plan catalogue: where the definitions live and how to find one.
 *
 * Reading a plan is Plan's job. This only resolves the source, which is wherever you
 * keep it: point `plans_from` at your own config file and a plan is defined once,
 * with its price and name beside its credits and ceilings.
 */
class Plans
{
    /** @var int Returned for a limit or an allowance that has no ceiling. */
    public const UNLIMITED = -1;

    /**
     * Every plan definition.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return config((string) config('larameter.plans_from', 'larameter.plans')) ?? [];
    }

    /**
     * Whether a handle names a defined plan.
     *
     * @param string|null $handle
     * @return bool
     */
    public static function exists(?string $handle): bool
    {
        return $handle !== null && array_key_exists($handle, static::all());
    }

    /**
     * A plan by handle. An unknown handle gives one that grants nothing.
     *
     * @param string|null $handle
     * @return Plan
     */
    public static function find(?string $handle): Plan
    {
        return new Plan((string) $handle, static::all()[$handle] ?? []);
    }
}
