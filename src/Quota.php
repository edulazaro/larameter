<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Contracts\Meter as MeterContract;
use Illuminate\Database\Eloquent\Model;

/**
 * The ceilings on how many of something may exist, for one thing you bill.
 *
 * Reached through the model, as $org->quota(). A different question from credits:
 * those are spent and come back, these are a standing count the plan caps.
 *
 * Its sisters are $org->plan(), which answers what was bought, and $org->usage(),
 * which answers what has been spent.
 */
class Quota
{
    /** @var array<string, MeterContract> Resolved once per quota. */
    private array $meters = [];

    /**
     * Create the ceiling view of a model.
     *
     * @param Model $meterable
     * @param array<int, class-string<MeterContract>> $meterClasses
     * @return void
     */
    public function __construct(
        protected Model $meterable,
        protected array $meterClasses = [],
    ) {
    }

    /**
     * Whether there is room for more of a resource. Unmetered resources are unlimited.
     *
     * A resource nobody counts has no ceiling, or a package you just installed would
     * start refusing to create things it was never told to count.
     *
     * @param string $handle
     * @param int $additional
     * @return bool
     */
    public function allows(string $handle, int $additional = 1): bool
    {
        return $this->get($handle)?->fits($additional) ?? true;
    }

    /**
     * One meter by handle, or null when that resource is not counted.
     *
     * @param string $handle
     * @return MeterContract|null
     */
    public function get(string $handle): ?MeterContract
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * Every meter in effect, keyed by handle.
     *
     * @return array<string, MeterContract>
     */
    public function all(): array
    {
        if ($this->meters !== []) {
            return $this->meters;
        }

        foreach (array_unique($this->meterClasses) as $meterClass) {
            $meter = new $meterClass($this->meterable);
            $this->meters[$meter->handle] = $meter;
        }

        return $this->meters;
    }

    /**
     * Every meter with its count and ceiling, for a usage screen.
     *
     * @return array<int, array{handle: string, label: string, count: int, limit: int}>
     */
    public function summary(): array
    {
        return array_map(fn (MeterContract $meter) => $meter->toArray(), array_values($this->all()));
    }
}
