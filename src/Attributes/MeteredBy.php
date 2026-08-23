<?php

namespace EduLazaro\Larameter\Attributes;

use Attribute;

/**
 * Declares a meter on the model it caps.
 *
 * Repeatable, and interchangeable with the $meters property.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class MeteredBy
{
    /**
     * Create a new attribute instance.
     *
     * @param  string  $meterClass
     * @return void
     */
    public function __construct(public string $meterClass)
    {
    }
}
