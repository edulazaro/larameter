<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Attributes\MeteredBy;
use EduLazaro\Larameter\Contracts\Meter;
use ReflectionClass;

/**
 * Put this on the model a plan caps, and declare its meters either way:
 *
 *     #[MeteredBy(MemberMeter::class)]
 *     class Organization extends Model { use HasMeters; }
 *
 *     // or, from a service provider
 *     Organization::meter(MemberMeter::class);
 *
 * Then nothing has to remember how to count:
 *
 *     $org->canCreate('members');
 *     $org->usageSummary();
 */
trait HasMeters
{
    /** @var array<string, array<int, class-string<Meter>>> Keyed by model, so subclasses do not share. */
    private static array $meterClasses = [];

    /** @var array<string, Meter> Resolved for this instance only. */
    private array $meterInstances = [];

    /** Read once per model, the first time anyone asks. */
    public static function bootHasMeters(): void
    {
        foreach ((new ReflectionClass(static::class))->getAttributes(MeteredBy::class) as $attribute) {
            static::meter($attribute->newInstance()->meterClass);
        }
    }

    /** @param class-string<Meter> $meterClass */
    public static function meter(string $meterClass): void
    {
        $registered = static::$meterClasses[static::class] ?? [];

        if (! in_array($meterClass, $registered, true)) {
            static::$meterClasses[static::class] = [...$registered, $meterClass];
        }
    }

    /** @return array<string, Meter> Keyed by the meter's key. */
    public function meters(): array
    {
        if ($this->meterInstances !== []) {
            return $this->meterInstances;
        }

        foreach (static::$meterClasses[static::class] ?? [] as $meterClass) {
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
