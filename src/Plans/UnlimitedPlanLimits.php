<?php

namespace EduLazaro\Laracredits\Plans;

use EduLazaro\Laracredits\Contracts\ProvidesPlanLimits;
use Illuminate\Database\Eloquent\Model;

/** Everything unlimited. The default until you bind your own. */
class UnlimitedPlanLimits implements ProvidesPlanLimits
{
    public function limitFor(Model $account, string $key): int
    {
        return -1;
    }
}
