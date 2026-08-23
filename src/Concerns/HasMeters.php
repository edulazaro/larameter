<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Contracts\Meter;

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
 * Then nothing has to remember how to count:
 *
 *     $org->canCreate('members');
 *     $org->usageSummary();
 */
trait HasMeters
{
    /**
     * Registered from outside the class, keyed by model so subclasses do not share.
     *
     * @var array<string, array<int, class-string<Meter>>>
     */
    private static array $registeredMeters = [];

    /** @var array<string, Meter> Resolved for this instance only. */
    private array $meterInstances = [];

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

        $declared = property_exists($this, 'meters') ? (array) $this->meters : [];
        $classes = array_unique([...$declared, ...(static::$registeredMeters[static::class] ?? [])]);

        foreach ($classes as $meterClass) {
            $meter = new $meterClass($this);
            $this->meterInstances[$meter->key()] = $meter;
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
