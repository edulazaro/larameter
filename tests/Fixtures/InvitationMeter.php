<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

/** Declares no handle: the class name has to say it. */
class InvitationMeter extends Meter
{
    public function count(): int
    {
        return 0;
    }
}
