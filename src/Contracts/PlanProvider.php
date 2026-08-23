<?php

namespace EduLazaro\Larameter\Contracts;

use EduLazaro\Larameter\Plan;
use Illuminate\Database\Eloquent\Model;

/**
 * A source a plan can be resolved from.
 *
 * Providers run in order and the first that answers wins, so the order is the policy:
 * an override before a subscription means a decision made by hand beats what the
 * billing provider thinks.
 */
interface PlanProvider
{
    /**
     * The plan this model is on, or null to let the next provider answer.
     *
     * @param  Model  $model
     * @return Plan|null
     */
    public function provide(Model $model): ?Plan;
}
