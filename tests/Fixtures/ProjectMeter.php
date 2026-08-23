<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

/** Not declared on the model: only ever added with Organization::meter(). */
class ProjectMeter extends Meter
{
    protected string $key = 'projects';

    public function count(): int
    {
        return 0;
    }
}
