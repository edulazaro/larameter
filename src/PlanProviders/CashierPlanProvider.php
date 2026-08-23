<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * The plan behind a live Cashier subscription, matched by price id.
 *
 * Cashier is detected, never required: without it this answers null and the next
 * provider takes over. Everything it knows about Cashier stays in here, so the Plan it
 * returns reads the same as any other.
 */
class CashierPlanProvider implements PlanProvider
{
    /**
     * The plan behind the live subscription, matched by price id.
     *
     * @param Model $model
     * @return Plan|null
     */
    public function provide(Model $model): ?Plan
    {
        if (! $this->available($model)) {
            return null;
        }

        $type = (string) config('larameter.subscription_type', 'default');

        if (! $model->subscribed($type)) {
            return null;
        }

        $priceKey = (string) config('larameter.price_id_key', 'stripe_price_id');

        foreach (Plans::all() as $handle => $config) {
            $priceId = $config[$priceKey] ?? null;

            if ($priceId && $model->subscribedToPrice($priceId, $type)) {
                return new Plan($handle, $config);
            }
        }
        return null;
    }

    /**
     * Whether Cashier is installed and this model is billable.
     *
     * @param Model $model
     * @return bool
     */
    protected function available(Model $model): bool
    {
        return class_exists(\Laravel\Cashier\Cashier::class)
            && method_exists($model, 'subscribed')
            && method_exists($model, 'subscribedToPrice');
    }
}
