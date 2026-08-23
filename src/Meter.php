<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Contracts\Meter as MeterContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Counts one resource a plan caps.
 *
 * Write one per resource and leave the rest to the base class:
 *
 *     class MemberMeter extends Meter
 *     {
 *         public function count(): int
 *         {
 *             return $this->meterable->members()->count();
 *         }
 *     }
 *
 * A class rather than a number passed in by the caller, because a ceiling enforced by
 * whoever remembers to count is a ceiling enforced in some places and not others.
 */
abstract class Meter implements MeterContract
{
    /** @var string Matches the key under `limits` in the plan. Derived from the class name if empty. */
    public string $handle = '';

    /**
     * Create a new meter for a model.
     *
     * @param Model $meterable
     * @return void
     */
    public function __construct(protected Model $meterable)
    {
        if ($this->handle === '') {
            $this->handle = static::handleFromClassName();
        }
    }

    /**
     * How many exist right now.
     *
     * @return int
     */
    abstract public function count(): int;

    /**
     * Display name. Derived from the handle unless overridden.
     *
     * @return string
     */
    public function label(): string
    {
        return Str::headline($this->handle);
    }

    /**
     * The ceiling for the current plan. -1 is unlimited.
     *
     * @return int
     */
    public function limit(): int
    {
        return $this->plan()->limit($this->handle);
    }

    /**
     * Whether more may be created.
     *
     * @param int $additional
     * @return bool
     */
    public function allows(int $additional = 1): bool
    {
        $limit = $this->limit();

        return $limit < 0 || ($this->count() + $additional) <= $limit;
    }

    /**
     * The meter as a row for a usage screen.
     *
     * @return array{handle: string, label: string, count: int, limit: int}
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label(),
            'count' => $this->count(),
            'limit' => $this->limit(),
        ];
    }

    /**
     * The handle a class name implies: MemberMeter counts `members`.
     *
     * @return string
     */
    protected static function handleFromClassName(): string
    {
        $subject = preg_replace('/Meter$/', '', class_basename(static::class));

        return Str::snake(Str::pluralStudly($subject));
    }

    /**
     * The plan the metered model is on.
     *
     * @return Plan
     */
    protected function plan(): Plan
    {
        return method_exists($this->meterable, 'plan')
            ? $this->meterable->plan()
            : new Plan('');
    }
}
