<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * The plan behind a live subscription, matched by price id.
 *
 * Cashier is detected and never required. Without it, or without a subscription, this
 * answers null and the next provider gets a turn, so an app that sells credit bundles
 * pays nothing for this being in the default list.
 *
 * Everything it knows about Cashier stays in here. A Plan is a plan: a handle, a name,
 * an allowance and some ceilings, and it should read the same whether it was resolved
 * from a subscription, from a column, or from a default.
 */
class CashierPlanProvider implements PlanProvider
{
    public function provide(Model $model): ?Plan
    {
        if (! $this->available($model)) {
            return null;
        }

        $type = (string) config('larameter.subscription_type', 'default');

        if (! $model->subscribed($type)) {
            return null;
        }

        foreach (Plans::all() as $handle => $config) {
            $priceId = (new Plan($handle, $config))->priceId();

            if ($priceId && $model->subscribedToPrice($priceId, $type)) {
                return new Plan($handle, $config);
            }
        }

        // Subscribed to a price no plan claims. Answering null hands it to the next
        // provider rather than inventing a plan, so a price you forgot to map shows up as
        // the default instead of as unlimited everything.
        return null;
    }

    protected function available(Model $model): bool
    {
        return class_exists(\Laravel\Cashier\Cashier::class)
            && method_exists($model, 'subscribed')
            && method_exists($model, 'subscribedToPrice');
    }
}
