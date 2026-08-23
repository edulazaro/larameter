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
    /**
     * Matches the key under `limits` in the plan. Declare it, or let the class name say
     * it: MemberMeter counts `members`, CompanyMeter counts `companies`, and MembersMeter
     * also counts `members` because pluralising an already plural word changes nothing.
     *
     * Not readonly: the constructor fills it in when the subclass left it alone.
     */
    public string $handle = '';

    public function __construct(
        protected Model $meterable,
    ) {
        if ($this->handle === '') {
            $this->handle = static::handleFromClassName();
        }
    }

    abstract public function count(): int;

    /**
     * The suffix comes off FIRST, then the word is pluralised.
     *
     * The other way round pluralises "Meter" and gives member_meters, which matches no
     * plan limit and so reads as unlimited: a cap that silently never applies.
     */
    protected static function handleFromClassName(): string
    {
        $subject = preg_replace('/Meter$/', '', class_basename(static::class));

        return Str::snake(Str::pluralStudly($subject));
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
        return Str::headline($this->handle);
    }

    /** The ceiling for this account's plan. -1 is unlimited. */
    public function limit(): int
    {
        return $this->plan()->limit($this->handle);
    }

    /** Whether one more may be created. */
    public function allows(int $additional = 1): bool
    {
        $limit = $this->limit();

        return $limit < 0 || ($this->count() + $additional) <= $limit;
    }

    /** @return array{handle: string, label: string, count: int, limit: int} */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
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
