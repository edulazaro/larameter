<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Contracts\Meter as MeterContract;
use EduLazaro\Larameter\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Counts one thing a plan caps.
 *
 * Write one per resource and leave it at that:
 *
 *     class MemberMeter extends Meter
 *     {
 *         protected string $key = 'members';
 *
 *         public function count(): int
 *         {
 *             return $this->meterable->members()->count();
 *         }
 *     }
 *
 * It exists because the alternative was making every caller do the counting. That is how
 * a cap ends up enforced in one place and forgotten in another: the plan says one seat,
 * the usage screen says one seat, and the invite form never asks.
 */
abstract class Meter implements MeterContract
{
    protected string $key = '';

    public function __construct(
        protected Model $meterable,
    ) {}

    abstract public function count(): int;

    public function key(): string
    {
        // Falls back to the class name so a meter that forgets its key still lands
        // somewhere predictable rather than on the empty string, where every plan lookup
        // would silently miss and every cap would read as unlimited.
        return $this->key !== ''
            ? $this->key
            : Str::snake(Str::pluralStudly(class_basename(static::class)));
    }

    /**
     * Whatever a usage screen should call it.
     *
     * Derived from the key, so the common case needs nothing. Override it to translate:
     * the package never sees the string and does not care where it came from, which is
     * why there is no dependency on any translation package here.
     *
     *     public function label(): string
     *     {
     *         return text('meters.members', 'Members');
     *     }
     */
    public function label(): string
    {
        return Str::headline($this->key());
    }

    /** The ceiling for this account's plan. -1 is unlimited. */
    public function limit(): int
    {
        return $this->plan()->limit($this->key());
    }

    /** Whether one more may be created. */
    public function allows(int $additional = 1): bool
    {
        $limit = $this->limit();

        return $limit < 0 || ($this->count() + $additional) <= $limit;
    }

    /** @return array{key: string, label: string, count: int, limit: int} */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'count' => $this->count(),
            'limit' => $this->limit(),
        ];
    }

    /** Whatever plan the thing being metered is on. Never null, so no null checks. */
    protected function plan(): Plan
    {
        return method_exists($this->meterable, 'plan')
            ? $this->meterable->plan()
            : new Plan('');
    }
}
