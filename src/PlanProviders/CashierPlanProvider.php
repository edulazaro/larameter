<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\CashierPlan;
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

        foreach (Plans::all() as $key => $config) {
            $priceId = $config[(string) config('larameter.price_id_key', 'stripe_price_id')] ?? null;

            if ($priceId && $model->subscribedToPrice($priceId, $type)) {
                return new CashierPlan($key, $config, $model->subscription($type));
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
