<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Attributes\MeteredBy;
use EduLazaro\Larameter\Contracts\Meter;
use EduLazaro\Larameter\Quota;
use ReflectionClass;

/**
 * Put this on a model whose plan caps how many of something it may have.
 *
 * Declare the meters as a plain list, since a meter knows its own handle:
 *
 *     protected array $meters = [MemberMeter::class, CaseMeter::class];
 *
 * The #[MeteredBy] attribute does the same, and meter() adds one from outside for a
 * model you cannot edit. Declaring the same meter twice does not double it.
 *
 * It brings one instance method, quota(), because a trait goes into somebody else's
 * class and every name it claims there is a name that class can no longer use. This one
 * learned it the hard way: it used to bring a meters(), which is a fatal error on any
 * model that also uses Cashier's Billable.
 */
trait HasMeters
{
    /** @var array<string, array<int, class-string<Meter>>> From #[MeteredBy], read once. */
    private static array $attributeMeters = [];

    /** @var array<string, array<int, class-string<Meter>>> Registered at runtime. */
    private static array $registeredMeters = [];

    /** @var Quota|null Resolved once per instance. */
    private ?Quota $quota = null;

    /**
     * Read the #[MeteredBy] attributes. Called by Eloquent when the model boots.
     *
     * @return void
     */
    public static function bootHasMeters(): void
    {
        static::$attributeMeters[static::class] = array_map(
            fn ($attribute) => $attribute->newInstance()->meterClass,
            (new ReflectionClass(static::class))->getAttributes(MeteredBy::class),
        );
    }

    /**
     * Add a meter from outside the class.
     *
     * @param class-string<Meter> $meterClass
     * @return void
     */
    public static function meter(string $meterClass): void
    {
        $registered = static::$registeredMeters[static::class] ?? [];

        if (! in_array($meterClass, $registered, true)) {
            static::$registeredMeters[static::class] = [...$registered, $meterClass];
        }
    }

    /**
     * Drop meters registered at runtime for this model.
     *
     * @return void
     */
    public static function flushRegisteredMeters(): void
    {
        unset(static::$registeredMeters[static::class]);
    }

    /**
     * Ceilings: how many of something exist, and how many the plan allows.
     *
     * @return Quota
     */
    public function quota(): Quota
    {
        return $this->quota ??= new Quota($this, $this->meterClasses());
    }

    /**
     * Every meter declared for this model, however it was declared.
     *
     * @return array<int, class-string<Meter>>
     */
    protected function meterClasses(): array
    {
        return array_unique([
            ...(property_exists($this, 'meters') ? (array) $this->meters : []),
            ...(static::$attributeMeters[static::class] ?? []),
            ...(static::$registeredMeters[static::class] ?? []),
        ]);
    }
}
