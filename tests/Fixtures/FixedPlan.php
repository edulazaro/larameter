<?php

namespace EduLazaro\Laracredits\Tests\Fixtures;

use EduLazaro\Laracredits\Contracts\ProvidesPlanLimits;
use Illuminate\Database\Eloquent\Model;

class FixedPlan implements ProvidesPlanLimits
{
    public function __construct(private array $limits = []) {}

    public function limitFor(Model $account, string $key): int
    {
        return $this->limits[$key] ?? -1;
    }
}
