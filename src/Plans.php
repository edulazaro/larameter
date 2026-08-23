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
    /** @return array<string, array<string, int>> */
    public static function all(): array
    {
        return config('larameter.plans') ?? [];
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, static::all());
    }

    /** @return array<string, int> */
    public static function limits(?string $key): array
    {
        return static::all()[$key] ?? [];
    }

    /**
     * Credits granted per period. 0 with no plan, -1 for unlimited.
     *
     * Absent means NONE here, unlike limit() below, and the asymmetry is deliberate:
     * credits are what you sell, so a plan that does not mention them does not include
     * any. Defaulting this to unlimited would give the whole product away to anybody on
     * a plan you forgot to fill in.
     */
    public static function credits(?string $key): int
    {
        return (int) (static::limits($key)['credits_monthly'] ?? 0);
    }

    /**
     * A ceiling on how many of something a plan allows: seats, projects, whatever.
     *
     * Absent means UNLIMITED here, the opposite of credits(), and for the same reason
     * read the other way round: these are restrictions, so one you never wrote down was
     * never meant to apply. A package you just installed should not start refusing to
     * create users because it has an opinion about how many you get.
     */
    public static function limit(?string $key, string $resource): int
    {
        return (int) (static::limits($key)[$resource] ?? -1);
    }
}
