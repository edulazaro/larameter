<?php

namespace EduLazaro\Larameter\Models;

use EduLazaro\Larameter\Plan;
use EduLazaro\Larameter\Plans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The credit account of one thing you bill.
 *
 * It holds only what does not expire: the plan, and credits bought on top. Consumption
 * lives in the windows, because an allowance is always an allowance PER something.
 *
 * Two buckets, and the plan goes first:
 *
 *   - the plan allowance, bounded by every window it declares and reset with them,
 *   - purchased credits, which sit outside all of them and survive every reset.
 *
 * Credits paid for out of the purchased bucket are deliberately NOT counted against the
 * windows. That is what makes topping up work the way people expect: you run out of
 * session, you buy more usage, you carry on, and your week has not moved meanwhile.
 */
class Account extends Model
{
    protected $table = 'larameter_accounts';

    protected $fillable = [
        'meterable_type',
        'meterable_id',
        'plan_key',
        'purchased_credits',
    ];

    protected $casts = [
        'purchased_credits' => 'integer',
    ];

    /** Memoised: headroom is asked several times per request and resolving can hit Stripe. */
    private ?Plan $cachedPlan = null;

    /** Whatever you bill: an organisation, a user, a workspace. */
    public function meterable(): MorphTo
    {
        return $this->morphTo();
    }

    public function windows(): HasMany
    {
        return $this->hasMany(Window::class, 'account_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'account_id');
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
            return static::firstOrCreate($keys, ['plan_key' => config('larameter.default_plan')]);
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

    /**
     * The plan whose allowance this account spends.
     *
     * Asks the thing being metered first, because with HasPlans on it the plan is worked
     * out from a subscription or an override and the stored column is only a fallback.
     * Reading the column here regardless was a real bug: plan() answered 'pro' while the
     * credits came from whatever 'free' granted, and nothing said so.
     *
     * The stored column is still the answer for a model with no plans at all.
     */
    public function planFor(): Plan
    {
        if ($this->cachedPlan !== null) {
            return $this->cachedPlan;
        }

        $meterable = $this->meterable;

        if ($meterable && method_exists($meterable, 'plan')) {
            return $this->cachedPlan = $meterable->plan();
        }

        return $this->cachedPlan = Plans::find($this->plan_key);
    }

    // ─── Reading ────────────────────────────────────────────────────

    /**
     * What the PLAN still allows, in whichever window is tightest.
     *
     * Reads only. An expired window is reported as full WITHOUT being restarted: on a
     * rolling window the row is the clock, so restarting it here would mean that opening
     * the app to see your balance burned the session.
     */
    public function headroom(): int
    {
        $declared = Window::declared();

        // No windows declared is an explicit opt-out of allowance metering: usage is
        // recorded, nothing is refused, and only purchased credits mean anything.
        if ($declared === []) {
            return PHP_INT_MAX;
        }

        $rows = $this->windows->keyBy('key');
        $headroom = PHP_INT_MAX;

        foreach (array_keys($declared) as $key) {
            // Validates the declaration on every read. Without this a window with no
            // length would work until the first row of it expired, which is days later
            // and nowhere near the config that caused it.
            Window::lengthOf($key);

            $allowance = $this->planFor()->credits($key);

            if ($allowance < 0) {
                continue;
            }

            $used = $rows->get($key)?->currentUsage() ?? 0;

            $headroom = min($headroom, max(0, $allowance - $used));
        }

        return $headroom;
    }

    /** Plan allowance left, plus anything bought on top. */
    public function remaining(): int
    {
        $headroom = $this->headroom();

        return $headroom === PHP_INT_MAX
            ? PHP_INT_MAX
            : $headroom + $this->purchased_credits;
    }

    public function hasCredits(int $credits = 1): bool
    {
        return $this->remaining() >= $credits;
    }

    /** When the tightest window lets them spend again, or null if nothing is blocking. */
    public function nextResetAt(): ?\DateTimeInterface
    {
        if ($this->headroom() > 0 || $this->purchased_credits > 0) {
            return null;
        }

        return $this->windows
            ->filter(fn (Window $w) => $this->planFor()->credits($w->key) >= 0)
            ->map(fn (Window $w) => $w->endsAt())
            ->sort()
            ->first();
    }

    // ─── Spending ───────────────────────────────────────────────────

    /**
     * Take credits off the balance. Plan allowance first, purchased for the overflow.
     *
     * Locked and in a transaction rather than read-then-write: the split needs to know
     * how much allowance is left, and two concurrent charges reading the same figure
     * would each bill the allowance for it and leave the purchased bucket untouched.
     * Credits given away for free, visible only as a slow drift.
     *
     * The account lock also serialises the window rows, which are written nowhere else.
     *
     * @return array{plan: int, purchased: int} what each bucket actually paid. They add
     *         up to less than what was charged exactly when the account overdrew, which
     *         is how an overdraft stays visible instead of being rounded away.
     */
    public function apply(int $credits): array
    {
        $nothing = ['plan' => 0, 'purchased' => 0];

        if ($credits <= 0) {
            return $nothing;
        }

        return DB::transaction(function () use ($credits, $nothing) {
            /** @var static|null $locked */
            $locked = static::query()->lockForUpdate()->find($this->getKey());

            if (! $locked) {
                return $nothing;
            }

            $locked->load('windows');

            $headroom = $locked->headroom();
            $fromPlan = $headroom === PHP_INT_MAX ? $credits : min($credits, $headroom);
            $overflow = $credits - $fromPlan;

            // Only touch the windows when the plan is actually paying. Opening a rolling
            // window to record a zero would start somebody's session for nothing.
            if ($fromPlan > 0) {
                foreach (array_keys(Window::declared()) as $key) {
                    $window = $locked->windows->firstWhere('key', $key);

                    if (! $window) {
                        $window = new Window([
                            'account_id' => $locked->getKey(),
                            'key' => $key,
                            'credits_used' => 0,
                            'started_at' => now(),
                        ]);
                    } elseif ($window->isExpired()) {
                        $window->restart();
                    }

                    $window->credits_used += $fromPlan;
                    $window->save();
                }

                $locked->unsetRelation('windows');
            }

            // Clamp rather than store a negative: a turn that started with credit is
            // allowed to finish, and a balance should not have to be read as a debt.
            $fromPurchased = min($overflow, $locked->purchased_credits);

            if ($fromPurchased > 0) {
                $locked->purchased_credits -= $fromPurchased;
                $locked->save();
            }

            $this->setRawAttributes($locked->getAttributes(), true);
            $this->unsetRelation('windows');

            return ['plan' => $fromPlan, 'purchased' => $fromPurchased];
        });
    }

    // ─── Plan and top-ups ───────────────────────────────────────────

    /**
     * Move to a plan, or to none.
     *
     * The windows are NOT restarted: an upgrade mid-week raises the ceiling over what has
     * already been spent, rather than handing a second allowance to whoever works out
     * they can upgrade and downgrade in the same afternoon.
     */
    public function setPlan(?string $key): void
    {
        $this->plan_key = $key;
        $this->cachedPlan = null;
        $this->save();
    }

    /**
     * Line the billing windows up with a period that has just started.
     *
     * Call it when they first pay and on every renewal, passing the timestamp Stripe
     * gives you. Without it the grid is laid down on first USE, so somebody who tried
     * the app on Tuesday and paid on Thursday gets a fresh allowance five days after
     * paying, every month.
     *
     * NEVER call it on a plan change. That is the door this package keeps shut on
     * purpose: upgrade, get a new allowance, downgrade, repeat. setPlan() does not touch
     * it, and neither should you.
     *
     * Two things keep it from being abused anyway:
     *
     *   - rolling windows are left alone. A session is not a billing period, and a
     *     renewal has no business handing somebody back the five hours they just spent.
     *   - passing the same instant twice does nothing. Since the instant comes from
     *     Stripe and Stripe gives you one per period, it cannot be replayed for a
     *     second allowance.
     */
    public function startPeriod(?\DateTimeInterface $at = null): void
    {
        $at = $at ? Carbon::instance($at) : now();

        foreach (array_keys(Window::declared()) as $key) {
            if (Window::anchorOf($key) !== 'fixed') {
                continue;
            }

            $window = $this->windows()->firstOrNew(['key' => $key]);

            if ($window->exists && $window->started_at->equalTo($at)) {
                continue;
            }

            $window->forceFill([
                'account_id' => $this->getKey(),
                'started_at' => $at,
                'credits_used' => 0,
            ])->save();
        }

        $this->unsetRelation('windows');
    }

    /** Credits in. The observer on Deposit moves purchased_credits to match. */
    public function deposit(
        int $credits,
        string $reason = 'purchase',
        ?Model $source = null,
        ?string $note = null,
        array $metadata = [],
    ): Deposit {
        return Deposit::create([
            'account_id' => $this->getKey(),
            'credits' => $credits,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
            'metadata' => $metadata ?: null,
        ]);
    }
}
