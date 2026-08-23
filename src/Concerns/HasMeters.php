<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Attributes\MeteredBy;
use EduLazaro\Larameter\Contracts\Meter;
use ReflectionClass;

/**
 * Put this on the model a plan caps, and list its meters:
 *
 *     class Organization extends Model
 *     {
 *         use HasCredits, HasMeters;
 *
 *         protected array $meters = [MemberMeter::class, CaseMeter::class];
 *     }
 *
 * A plain list and not a map, because a meter already knows its own key.
 *
 * Three ways in, and they combine: the $meters property, the #[MeteredBy] attribute, and
 * meter() from outside. Declaring the same meter twice does not double it.
 *
 * Then nothing has to remember how to count:
 *
 *     $org->canCreate('members');
 *     $org->usageSummary();
 */
trait HasMeters
{
    /**
     * From #[MeteredBy], read once per model. Kept apart from the ones registered at
     * runtime so that flushing those does not also erase what the class declares.
     *
     * @var array<string, array<int, class-string<Meter>>>
     */
    private static array $attributeMeters = [];

    /**
     * Registered from outside the class, keyed by model so subclasses do not share.
     *
     * @var array<string, array<int, class-string<Meter>>>
     */
    private static array $registeredMeters = [];

    /** @var array<string, Meter> Resolved for this instance only. */
    private array $meterInstances = [];

    /**
     * Read the attributes, once per model, the first time Eloquent boots it.
     *
     * Named bootHasMeters so Eloquent's own trait booting picks it up. Same mechanism as
     * larakeep uses for #[KeptBy].
     */
    public static function bootHasMeters(): void
    {
        static::$attributeMeters[static::class] = array_map(
            fn ($attribute) => $attribute->newInstance()->meterClass,
            (new ReflectionClass(static::class))->getAttributes(MeteredBy::class),
        );
    }

    /**
     * Add a meter to a model you cannot edit: a module that brings its own relation and
     * wants it capped, or a meter that only applies when something is switched on.
     *
     * The same pair as $casts and mergeCasts(): the property declares, this one adds.
     *
     * @param class-string<Meter> $meterClass
     */
    public static function meter(string $meterClass): void
    {
        $registered = static::$registeredMeters[static::class] ?? [];

        if (! in_array($meterClass, $registered, true)) {
            static::$registeredMeters[static::class] = [...$registered, $meterClass];
        }
    }

    /**
     * Drop everything registered from outside, for this model.
     *
     * The store is static, so without this a test that registers a meter leaks it into
     * every test that runs after it, and the suite starts depending on its own order.
     */
    public static function flushRegisteredMeters(): void
    {
        unset(static::$registeredMeters[static::class]);
    }

    /** @return array<string, Meter> Keyed by the meter's key. */
    public function meters(): array
    {
        if ($this->meterInstances !== []) {
            return $this->meterInstances;
        }

        $classes = array_unique([
            ...(property_exists($this, 'meters') ? (array) $this->meters : []),
            ...(static::$attributeMeters[static::class] ?? []),
            ...(static::$registeredMeters[static::class] ?? []),
        ]);

        foreach ($classes as $meterClass) {
            $meter = new $meterClass($this);
            $this->meterInstances[$meter->handle] = $meter;
        }

        return $this->meterInstances;
    }

    public function meterFor(string $key): ?Meter
    {
        return $this->meters()[$key] ?? null;
    }

    /**
     * Whether one more of something may be created.
     *
     * A resource with NO meter is unlimited. Getting that backwards would mean a package
     * you just installed refuses to create things it was never told to count.
     */
    public function canCreate(string $key, int $additional = 1): bool
    {
        return $this->meterFor($key)?->allows($additional) ?? true;
    }

    /** Everything declared, for a usage screen. */
    public function usageSummary(): array
    {
        return array_map(fn (Meter $meter) => $meter->toArray(), array_values($this->meters()));
    }
}
