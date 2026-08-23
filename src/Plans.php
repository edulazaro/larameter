<?php

namespace EduLazaro\Larameter;

/**
 * Plans, read from config.
 *
 * They live in the package because almost every app that meters credits also sells them
 * in tiers, and making each one reinvent that was the wrong trade. They are optional all
 * the same: an account with no plan_key is valid and spends purchased credits only.
 *
 * There is nothing to implement. Name your plans in config/larameter.php and put the key
 * on the account.
 */
class Plans
{
    public const UNLIMITED = -1;

    /** @return array<string, array> */
    public static function all(): array
    {
        return config('larameter.plans') ?? [];
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, static::all());
    }

    /** @return array<string, int> The allowance per window. Empty when there is none. */
    public static function credits(?string $key): array
    {
        $plan = static::all()[$key] ?? [];

        return $plan['credits'] ?? [];
    }

    /**
     * The allowance in one window.
     *
     * The two zero-ish answers mean different things and it matters which you get:
     *
     *   - the plan grants nothing at all (no plan, or no `credits` key) => 0, and every
     *     credit has to come from what was purchased.
     *   - the plan grants credits but says nothing about THIS window => unlimited, so a
     *     plan capped weekly is not accidentally also capped per session.
     */
    public static function creditsIn(?string $key, string $window): int
    {
        $credits = static::credits($key);

        if ($credits === []) {
            return 0;
        }

        return (int) ($credits[$window] ?? static::UNLIMITED);
    }

    /**
     * A ceiling on how many of something a plan allows: seats, projects, whatever.
     *
     * Absent means UNLIMITED here, the opposite of credits, and for the same reason read
     * the other way round: these are restrictions, so one you never wrote down was never
     * meant to apply.
     */
    public static function limit(?string $key, string $resource): int
    {
        $plan = static::all()[$key] ?? [];

        return (int) ($plan['limits'][$resource] ?? static::UNLIMITED);
    }
}
