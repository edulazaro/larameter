<?php

namespace EduLazaro\Larameter\Contracts;

use EduLazaro\Larameter\Plan;
use Illuminate\Database\Eloquent\Model;

/**
 * One place a plan can come from.
 *
 * They run in order and the first that answers wins, so the order is the policy: an
 * override before a subscription says a person's decision beats what Stripe thinks.
 *
 * Return null to pass to the next one. A provider that cannot answer is normal, not a
 * failure: without Cashier installed, CashierPlanProvider simply never answers.
 */
interface PlanProvider
{
    public function provide(Model $model): ?Plan;
}
