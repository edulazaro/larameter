<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * The plan stored on the account, then the configured default.
 *
 * Last in the order, and the only one an app without subscriptions reaches.
 */
class StoredPlanProvider implements PlanProvider
{
    /**
     * The plan stored on the account, then the configured default.
     *
     * @param Model $model
     * @return Plan|null
     */
    public function provide(Model $model): ?Plan
    {
        $stored = method_exists($model, 'usage')
            ? $model->usage()->account()->plan
            : null;

        $handle = $stored ?? config('larameter.default_plan');

        return $handle ? Plans::find($handle) : null;
    }
}
