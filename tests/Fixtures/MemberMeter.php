<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

class MemberMeter extends Meter
{
    protected string $key = 'members';

    public function count(): int
    {
        return $this->meterable->members()->count();
    }
}
