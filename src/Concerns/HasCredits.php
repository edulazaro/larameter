<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Usage;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Put this on whatever you bill: an organisation, a team, a user, a workspace.
 *
 * That is the whole setup. Publish the config, name your plans, add this line: no
 * interface to implement, nothing to bind, and no column on your own table.
 *
 * It brings one method, on purpose. Everything to do with credits hangs off
 * $org->usage(), because a trait goes into somebody else's class and every name it
 * claims there is a name that class can no longer use. Two traits claiming one name is
 * a fatal error when the class is compiled.
 *
 * Enough on its own for an app that sells bundles of credits. Add HasPlans for an
 * allowance and HasMeters for ceilings.
 */
trait HasCredits
{
    /** @var Usage|null Resolved once per instance. */
    private ?Usage $usage = null;

    /**
     * Credits: the balance, what things cost, charging, and topping up.
     *
     * @return Usage
     */
    public function usage(): Usage
    {
        return $this->usage ??= new Usage($this);
    }

    /**
     * The account row, for eager loading. Null until the model has one.
     *
     * @return MorphOne
     */
    public function meterAccount(): MorphOne
    {
        return $this->morphOne(Account::class, 'meterable');
    }
}
