<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\PlanProviders\CashierPlanProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * CashierPlanProvider with its "is Cashier installed" guard lifted.
 *
 * Everything else runs for real: matching the price id, building a CashierPlan, and
 * answering null when the subscribed price belongs to no plan. Without this the whole
 * provider is dead code in the suite, which is how it shipped untested the first time.
 */
class FakeCashierPlanProvider extends CashierPlanProvider
{
    protected function available(Model $model): bool
    {
        return method_exists($model, 'subscribed') && method_exists($model, 'subscribedToPrice');
    }
}
