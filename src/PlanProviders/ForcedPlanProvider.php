<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan set by hand in a column of your own: courtesy accounts, demos, partners.
 *
 * First in the default order, so it beats a subscription.
 */
class ForcedPlanProvider implements PlanProvider
{
    /**
     * The plan named by the override column, if it names a real one.
     *
     * @param Model $model
     * @return Plan|null
     */
    public function provide(Model $model): ?Plan
    {
        $column = config('larameter.override_column');

        if (! $column) {
            return null;
        }

        $handle = $model->getAttribute($column);
        return $handle && Plans::exists($handle) ? Plans::find($handle) : null;
    }
}
