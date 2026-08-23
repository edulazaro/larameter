<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

/** Already plural, and irregular. Both have to survive the derivation. */
class CompaniesMeter extends Meter
{
    public function count(): int
    {
        return 0;
    }
}
