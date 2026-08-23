<?php

namespace EduLazaro\Larameter\Plans;

use EduLazaro\Larameter\Contracts\ProvidesPlanLimits;
use Illuminate\Database\Eloquent\Model;

/**
 * Plans read from config, which is where most apps keep them.
 *
 * Bound by default, so publishing the config and naming your plans is the whole setup.
 * The contract is still there for plans that live in a table or carry per-customer
 * overrides, but nobody should have to implement an interface to charge for a form.
 *
 * An account's plan comes from creditPlanKey() if it has one (the HasCredits trait
 * provides it), otherwise from a `plan` attribute, otherwise the configured default.
 */
class ConfigPlanLimits implements ProvidesPlanLimits
{
    public function limitFor(Model $account, string $key): int
    {
        $plan = $this->planKeyFor($account);
        $limits = config("larameter.plans.{$plan}", []);

        // An unlisted key is unlimited rather than zero. Getting that backwards means a
        // package you just installed starts refusing things you never meant to limit.
        return (int) ($limits[$key] ?? -1);
    }

    protected function planKeyFor(Model $account): string
    {
        if (method_exists($account, 'creditPlanKey')) {
            $plan = $account->creditPlanKey();
        } else {
            $plan = $account->getAttribute('plan');
        }

        return $plan ?: (string) config('larameter.default_plan', 'free');
    }
}
