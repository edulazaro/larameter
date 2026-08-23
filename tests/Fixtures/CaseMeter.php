<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Meter;

class CaseMeter extends Meter
{
    protected string $key = 'cases';

    public function label(): string
    {
        return 'Expedientes';
    }

    public function count(): int
    {
        return $this->meterable->cases()->count();
    }
}
