<?php

namespace EduLazaro\Larameter;

/**
 * The catalogue: where the plan definitions are and how to find one.
 *
 * Reading a plan is Plan's job, not this one's. This only knows where they live, which is
 * wherever you keep them: point `plans_from` at your own file and a plan is defined once,
 * with its price and its name beside its credits and its ceilings.
 *
 * Plans are optional throughout. An account with no plan is valid and spends purchased
 * credits only, which is what you want if you sell bundles rather than subscriptions.
 */
class Plans
{
    public const UNLIMITED = -1;

    /** @return array<string, array> */
    public static function all(): array
    {
        return config((string) config('larameter.plans_from', 'larameter.plans')) ?? [];
    }

    public static function exists(?string $handle): bool
    {
        return $handle !== null && array_key_exists($handle, static::all());
    }

    /** Always a Plan. An unknown handle gives one that grants nothing. */
    public static function find(?string $handle): Plan
    {
        return new Plan((string) $handle, static::all()[$handle] ?? []);
    }
}
