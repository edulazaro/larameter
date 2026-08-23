<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * What setCreditPlan() wrote on the account, then the configured default.
 *
 * Last in the order, and the only one an app without subscriptions ever reaches. That is
 * the whole point of it: sell bundles, put somebody on a plan by hand, and none of the
 * rest of this costs you anything.
 */
class StoredPlanProvider implements PlanProvider
{
    public function provide(Model $model): ?Plan
    {
        $stored = method_exists($model, 'creditAccount')
            ? $model->creditAccount()->plan
            : null;

        $key = $stored ?? config('larameter.default_plan');

        return $key ? Plans::find($key) : null;
    }
}
