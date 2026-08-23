<?php

namespace EduLazaro\Larameter\PlanProviders;

use EduLazaro\Larameter\Contracts\PlanProvider;
use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan set by hand, in a column of your own: courtesy accounts, demos, partners.
 *
 * First in the default order, so it beats the subscription. Somebody decided this
 * deliberately and Stripe should not quietly undo it.
 */
class ForcedPlanProvider implements PlanProvider
{
    public function provide(Model $model): ?Plan
    {
        $column = config('larameter.override_column');

        if (! $column) {
            return null;
        }

        $key = $model->getAttribute($column);

        // Ignored when it names a plan that no longer exists, rather than leaving the
        // account on one whose credits and limits cannot be read, which behaves like
        // unlimited everything.
        return $key && Plans::exists($key) ? Plans::find($key) : null;
    }
}
