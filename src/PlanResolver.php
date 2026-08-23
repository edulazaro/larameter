<?php

namespace EduLazaro\Larameter;

use Illuminate\Database\Eloquent\Model;

/**
 * Works out which plan something is on.
 *
 * Three sources, in this order, and the order is the whole logic:
 *
 *   1. An override column, for courtesy accounts, demos and partners. It wins over money
 *      on purpose: somebody decided this by hand and Stripe should not undo it.
 *   2. The subscription, matched by price id.
 *   3. The default.
 *
 * Cashier is optional and detected, not required. Without it, or without a subscription,
 * the stored plan_key on the account is used instead, which is what an app that sells
 * credit bundles and no subscriptions wants.
 */
class PlanResolver
{
    /**
     * Always a Plan, never null, so nothing downstream needs a null check. An account on
     * no plan gets one with an empty key, which grants no credits, no features, and no
     * ceilings.
     */
    public function resolve(Model $model): Plan
    {
        $key = $this->resolveKey($model);

        return new Plan((string) $key, $key === null ? [] : (Plans::all()[$key] ?? []));
    }

    /** Null when nothing resolves it, which is a valid state: credits and no plan. */
    public function resolveKey(Model $model): ?string
    {
        $forced = $this->forcedKey($model);

        if ($forced !== null) {
            return $forced;
        }

        $subscribed = $this->subscribedKey($model);

        if ($subscribed !== null) {
            return $subscribed;
        }

        // Nothing resolved it, so fall back to what was stored. That is the only source
        // for an app with no subscriptions at all, and the reason the column exists.
        $stored = method_exists($model, 'creditAccount') ? $model->creditAccount()->plan_key : null;

        return $stored ?? config('larameter.default_plan');
    }

    /** A plan set by hand. Beats the subscription, because a person decided it. */
    protected function forcedKey(Model $model): ?string
    {
        $column = config('larameter.override_column');

        if (! $column) {
            return null;
        }

        $forced = $model->getAttribute($column);

        // Ignored when it names a plan that no longer exists, rather than leaving the
        // account on a plan whose limits cannot be read.
        return $forced && Plans::exists($forced) ? $forced : null;
    }

    /** The live subscription, matched to a plan by its price id. */
    protected function subscribedKey(Model $model): ?string
    {
        if (! $this->canAskCashier($model)) {
            return null;
        }

        $type = (string) config('larameter.subscription_type', 'default');

        if (! $model->subscribed($type)) {
            return null;
        }

        foreach (Plans::all() as $key => $config) {
            $plan = new Plan($key, $config);
            $priceId = $plan->priceId();

            if ($priceId && $model->subscribedToPrice($priceId, $type)) {
                return $key;
            }
        }

        return null;
    }

    protected function canAskCashier(Model $model): bool
    {
        return class_exists(\Laravel\Cashier\Cashier::class)
            && method_exists($model, 'subscribed')
            && method_exists($model, 'subscribedToPrice');
    }
}
