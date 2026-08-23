<?php

namespace EduLazaro\Larameter\Attributes;

use Attribute;

/**
 * Declares a meter above the class it caps.
 *
 *     #[MeteredBy(MemberMeter::class)]
 *     #[MeteredBy(CaseMeter::class)]
 *     class Organization extends Model
 *
 * Repeatable, and interchangeable with the $meters property. Same shape as #[KeptBy] in
 * larakeep, so a model that already declares keepers reads the same way here.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class MeteredBy
{
    public function __construct(
        public string $meterClass,
    ) {}
}
