<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

class MemberMeter extends Meter
{
    public string $handle = 'members';

    public function count(): int
    {
        return $this->meterable->members()->count();
    }
}
