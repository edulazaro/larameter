<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Attributes\MeteredBy;
use EduLazaro\Larameter\Contracts\Meter;
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
 */
trait HasMeters
{
    /** @var array<string, array<int, class-string<Meter>>> From #[MeteredBy], read once. */
    private static array $attributeMeters = [];

    /** @var array<string, array<int, class-string<Meter>>> Registered at runtime. */
    private static array $registeredMeters = [];

    /** @var array<string, Meter> Resolved once per instance. */
    private array $meterInstances = [];

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
     * Every meter in effect, keyed by handle.
     *
     * @return array<string, Meter>
     */
    public function getMeters(): array
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

    /**
     * One meter by handle.
     *
     * @param string $handle
     * @return Meter|null
     */
    public function getMeter(string $handle): ?Meter
    {
        return $this->getMeters()[$handle] ?? null;
    }

    /**
     * Whether there is room for more of a resource. Unmetered resources are unlimited.
     *
     * @param string $handle
     * @param int $additional
     * @return bool
     */
    public function fits(string $handle, int $additional = 1): bool
    {
        return $this->getMeter($handle)?->fits($additional) ?? true;
    }

    /**
     * Every meter with its count and ceiling, for a usage screen.
     *
     * @return array<int, array{handle: string, label: string, count: int, limit: int}>
     */
    public function usageSummary(): array
    {
        return array_map(fn (Meter $meter) => $meter->toArray(), array_values($this->getMeters()));
    }
}
