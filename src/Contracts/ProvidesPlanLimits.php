<?php

namespace EduLazaro\Larameter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * What an account is entitled to.
 *
 * The seam that keeps your pricing out of this package. Plans, tiers, grandfathered
 * deals, an override for one customer who negotiated hard: all of that is yours. The
 * package only ever asks "how much of X does this account get", and takes the answer.
 *
 * Return -1 for unlimited, which is different from 0.
 */
interface ProvidesPlanLimits
{
    public function limitFor(Model $account, string $key): int;
}
