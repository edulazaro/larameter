<?php

namespace EduLazaro\Larameter\Models;

use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The credit account of one thing you bill. This is the balance, and the source of truth
 * for what may still be spent.
 *
 * Two buckets, because they behave differently:
 *
 *   - the plan allowance, which resets every period,
 *   - purchased credits, which accumulate and survive the reset.
 *
 * Spending goes through the allowance FIRST, so what somebody paid for does not quietly
 * evaporate at the turn of a month.
 *
 * `plan_key` is nullable on purpose. Credits with no plan behind them is a real product:
 * you sell a bundle, they spend it, they buy another.
 */
class Account extends Model
{
    protected $table = 'larameter_accounts';

    protected $fillable = [
        'meterable_type',
        'meterable_id',
        'plan_key',
        'purchased_credits',
        'period_credits_used',
        'period_started_at',
    ];

    protected $casts = [
        'purchased_credits' => 'integer',
        'period_credits_used' => 'integer',
        'period_started_at' => 'datetime',
    ];

    /** Whatever you bill: an organisation, a user, a workspace. */
    public function meterable(): MorphTo
    {
        return $this->morphTo();
    }

    public function usage(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'account_id');
    }

    /**
     * The account for a model, created on first sight so nothing has to be provisioned
     * up front.
     */
    public static function for(Model $meterable): static
    {
        $keys = [
            'meterable_type' => $meterable->getMorphClass(),
            'meterable_id' => $meterable->getKey(),
        ];

        try {
            return static::firstOrCreate($keys, [
                'plan_key' => config('larameter.default_plan'),
                'period_started_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Two requests hit a new account at once and the unique index caught the
            // loser. The row exists now, which is all we wanted.
            $existing = static::where($keys)->first();

            if (! $existing) {
                throw $e;
            }

            return $existing;
        }
    }

    // ─── Balance ────────────────────────────────────────────────────

    /** What the plan grants per period. 0 with no plan, -1 for unlimited. */
    public function allowance(): int
    {
        return Plans::credits($this->plan_key);
    }

    public function remaining(): int
    {
        if ($this->allowance() < 0) {
            return PHP_INT_MAX;
        }

        $this->rollPeriod();

        return max(0, $this->allowance() - $this->period_credits_used) + $this->purchased_credits;
    }

    public function hasCredits(int $credits = 1): bool
    {
        return $this->remaining() >= $credits;
    }

    /**
     * Take credits off the balance. Allowance first, purchased for the overflow.
     *
     * Locked and in a transaction rather than read-then-write: the split needs to know
     * how much allowance is left, and two concurrent charges reading the same figure
     * would each bill the allowance for it and leave the purchased bucket untouched.
     * Credits given away for free, and only visible as a slow drift.
     */
    public function apply(int $credits): void
    {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($credits) {
            /** @var static $locked */
            $locked = static::query()->lockForUpdate()->find($this->getKey());

            if (! $locked) {
                return;
            }

            $locked->rollPeriod();

            $allowance = $locked->allowance();

            if ($allowance < 0) {
                // Unlimited. Still counted, so usage is reportable, but nothing is ever
                // taken from what they bought.
                $locked->period_credits_used += $credits;
                $locked->save();
                $this->refreshFrom($locked);

                return;
            }

            $fromAllowance = min($credits, max(0, $allowance - $locked->period_credits_used));

            $locked->period_credits_used += $fromAllowance;

            // Overdraft is possible: a turn that starts with credit is allowed to finish.
            // Clamp at zero rather than storing a negative, and let the usage rows carry
            // the truth of what was spent.
            $locked->purchased_credits = max(0, $locked->purchased_credits - ($credits - $fromAllowance));

            $locked->save();

            $this->refreshFrom($locked);
        });
    }

    /** Credits bought. These do not expire with the period. */
    public function addCredits(int $credits): void
    {
        if ($credits <= 0) {
            return;
        }

        $this->increment('purchased_credits', $credits);
    }

    /**
     * Move to a new plan.
     *
     * The period is NOT restarted: an upgrade mid-month raises the ceiling on what has
     * already been spent, rather than handing out a second allowance to whoever notices
     * they can upgrade and downgrade in the same afternoon.
     */
    public function setPlan(?string $key): void
    {
        $this->plan_key = $key;
        $this->save();
    }

    /**
     * Start a new period if the old one has run out.
     *
     * Advances one period at a time so a dormant account that skipped four months gets
     * one allowance on its return, not four.
     */
    public function rollPeriod(): void
    {
        $start = $this->period_started_at;

        if (! $start) {
            $this->forceFill([
                'period_started_at' => now()->startOfDay(),
                'period_credits_used' => 0,
            ])->save();

            return;
        }

        if ($start->copy()->addMonth()->isFuture()) {
            return;
        }

        // isFuture() and not isPast(), so the exact boundary instant belongs to the new
        // period rather than to the one that just ended.
        while (! $start->copy()->addMonth()->isFuture()) {
            $start = $start->copy()->addMonth();
        }

        // The loop leaves $start on the period that is running NOW, which is the one
        // to record.
        $this->forceFill([
            'period_started_at' => $start,
            'period_credits_used' => 0,
        ])->save();
    }

    private function refreshFrom(self $other): void
    {
        $this->setRawAttributes($other->getAttributes(), true);
    }
}
