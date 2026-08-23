<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;

/**
 * Put this on a model that can be on a plan.
 *
 * Separate from HasCredits because plans are optional: an app selling bundles of
 * credits uses HasCredits alone and none of this exists for it.
 *
 * It brings one method to read with, plan(), because a trait goes into somebody else's
 * class and every name it claims there is a name that class can no longer use.
 *
 * Where a plan comes from is a list of providers, tried in order, first answer wins.
 * The default list is in config, since how billing works has one answer per project.
 * Declare $planProviders on the model, or call setPlanProviders(), only when one model
 * is billed differently from another.
 */
trait HasPlans
{
    /** @var array<string, array<int, class-string<PlanProvider>>>. */
    private static array $registeredPlanProviders = [];

    /** @var ?Plan Resolved once per instance. */
    private ?Plan $resolvedPlan = null;

    /**
     * Set the providers for this model, overriding the config default.
     *
     * @param array<int, class-string<PlanProvider>> $providers
     * @return void
     */
    public static function setPlanProviders(array $providers): void
    {
        static::$registeredPlanProviders[static::class] = $providers;
    }

    /**
     * Drop providers registered at runtime for this model.
     *
     * @return void
     */
    public static function flushPlanProviders(): void
    {
        unset(static::$registeredPlanProviders[static::class]);
    }

    /**
     * The providers in effect, most specific source first.
     *
     * @return array<int, class-string<PlanProvider>>
     */
    protected function planProviders(): array
    {
        return static::$registeredPlanProviders[static::class]
            ?? (property_exists($this, 'planProviders') ? (array) $this->planProviders : null)
            ?? (array) config('larameter.plan_providers', []);
    }

    /**
     * The plan this model is on. Never null.
     *
     * @return Plan
     */
    public function plan(): Plan
    {
        // Memoised: resolving can query a subscription, and this is asked repeatedly.
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

    /**
     * Forget the resolved plan, after a subscription changes mid-request.
     *
     * @return void
     */
    public function forgetPlan(): void
    {
        $this->resolvedPlan = null;
    }
}
