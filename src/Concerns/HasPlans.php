<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;

/**
 * Put this on a model that can be on a plan.
 *
 * Separate from HasCredits on purpose, because plans really are optional: an app that
 * sells bundles of credits and nothing else uses HasCredits alone, and none of this
 * exists for it.
 *
 *     $org->plan();                        a Plan, never null
 *     $org->plan()->exists();              false when there is none
 *     $org->plan()->allows('api_access');
 *     $org->onPlan('pro');
 *
 * Where the plan comes from is a list of providers, tried in order, first answer wins.
 * The default list is in config, because how billing works is one answer per app. Set
 * $planProviders on the model, or call setPlanProviders(), only when one model is billed
 * differently from another.
 */
trait HasPlans
{
    /** @var array<string, array<int, class-string<PlanProvider>>> Keyed by model. */
    private static array $registeredPlanProviders = [];

    private ?Plan $resolvedPlan = null;

    /** @param array<int, class-string<PlanProvider>> $providers */
    public static function setPlanProviders(array $providers): void
    {
        static::$registeredPlanProviders[static::class] = $providers;
    }

    public static function flushPlanProviders(): void
    {
        unset(static::$registeredPlanProviders[static::class]);
    }

    /** @return array<int, class-string<PlanProvider>> */
    public function planProviders(): array
    {
        return static::$registeredPlanProviders[static::class]
            ?? (property_exists($this, 'planProviders') ? (array) $this->planProviders : null)
            ?? (array) config('larameter.plan_providers', []);
    }

    /**
     * The plan, worked out rather than stored.
     *
     * Never null, so nothing downstream needs a null check: with no provider answering
     * you get a plan that grants no credits, no features and no ceilings, and exists()
     * tells that apart from a plan that happens to grant little.
     */
    public function plan(): Plan
    {
        // Memoised per instance: resolving can ask Cashier, and this gets called several
        // times in a request.
        if ($this->resolvedPlan !== null) {
            return $this->resolvedPlan;
        }

        foreach ($this->planProviders() as $providerClass) {
            $plan = app($providerClass)->provide($this);

            if ($plan !== null) {
                return $this->resolvedPlan = $plan;
            }
        }

        return $this->resolvedPlan = new Plan('');
    }

    public function onPlan(string $handle): bool
    {
        return $this->plan()->handle === $handle;
    }

    /** Forget the resolved plan, after a subscription changes mid-request. */
    public function forgetPlan(): void
    {
        $this->resolvedPlan = null;
    }
}
