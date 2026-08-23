<?php

namespace EduLazaro\Larameter\Attributes;

use Attribute;

/**
 * Declares a meter on the model it caps, where anyone reading that model will see it.
 *
 *     #[MeteredBy(MemberMeter::class)]
 *     #[MeteredBy(CaseMeter::class)]
 *     class Organization extends Model
 *
 * Repeatable, and equivalent to calling Organization::meter(MemberMeter::class) from a
 * service provider. Use whichever suits: the attribute keeps the declaration next to the
 * model, the static call suits meters that are conditional on something.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class MeteredBy
{
    public function __construct(
        public string $meterClass,
    ) {}
}
