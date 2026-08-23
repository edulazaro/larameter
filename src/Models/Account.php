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
 * Holds only what does not expire: the plan and credits bought on top. Consumption
 * lives in the windows, since an allowance is always an allowance per something.
 *
 * Two buckets, and the plan goes first. Purchased credits sit outside every window and
 * what they pay for is not counted against one, so running out of session, buying more
 * and carrying on leaves the week where it was.
 */
class Account extends Model
{
    protected $table = 'larameter_accounts';

    protected $fillable = [
        'meterable_type',
        'meterable_id',
        'plan',
        'purchased_credits',
    ];

    protected $casts = [
        'purchased_credits' => 'integer',
    ];

    /** @var Plan|null Memoised: headroom is asked repeatedly and resolving may query. */
    private ?Plan $cachedPlan = null;

    /**
     * Whatever is being billed: an organisation, a user, a workspace.
     *
     * @return MorphTo
     */
    public function meterable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every window this account spends against.
     *
     * @return HasMany
     */
    public function windows(): HasMany
    {
        return $this->hasMany(Window::class, 'account_id');
    }

    /**
     * Every credit added to this account.
     *
     * @return HasMany
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'account_id');
    }

    /**
     * Every credit charged to this account.
     *
     * @return HasMany
     */
    public function usage(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'account_id');
    }

    /**
     * The account for a model, created on first sight.
     *
     * @param Model $meterable
     * @return static
     */
    public static function for(Model $meterable): static
    {
        $keys = [
            'meterable_type' => $meterable->getMorphClass(),
            'meterable_id' => $meterable->getKey(),
        ];

        try {
            return static::firstOrCreate($keys, ['plan' => config('larameter.default_plan')]);
        } catch (QueryException $e) {
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
     * Asks the metered model first: with HasPlans the plan is resolved and the stored
     * column is only a fallback. Reading the column regardless would let plan() answer
     * one thing while the credits came from another.
     *
     * @return Plan
     */
    public function plan(): Plan
    {
        if ($this->cachedPlan !== null) {
            return $this->cachedPlan;
        }

        $meterable = $this->meterable;

        if ($meterable && method_exists($meterable, 'plan')) {
            return $this->cachedPlan = $meterable->plan();
        }

        return $this->cachedPlan = Plans::find($this->plan);
    }

    /**
     * What the plan still allows, in whichever window is tightest.
     *
     * Reads only: an expired window reports as full without being restarted, or opening
     * the app to check a balance would start a session.
     *
     * @return int
     */
    public function headroom(): int
    {
        $declared = Window::declared();
        if ($declared === []) {
            return PHP_INT_MAX;
        }

        $rows = $this->windows->keyBy('key');
        $headroom = PHP_INT_MAX;

        foreach (array_keys($declared) as $key) {
            Window::lengthOf($key);

            $allowance = $this->plan()->creditsIn($key);

            if ($allowance < 0) {
                continue;
            }

            $used = $rows->get($key)?->currentUsage() ?? 0;

            $headroom = min($headroom, max(0, $allowance - $used));
        }

        return $headroom;
    }

    /**
     * Plan allowance left, plus anything purchased.
     *
     * @return int
     */
    public function remaining(): int
    {
        $headroom = $this->headroom();

        return $headroom === PHP_INT_MAX
            ? PHP_INT_MAX
            : $headroom + $this->purchased_credits;
    }

    /**
     * Whether there is enough left to spend.
     *
     * @param int $credits
     * @return bool
     */
    public function hasCredits(int $credits = 1): bool
    {
        return $this->remaining() >= $credits;
    }

    /**
     * When the tightest window allows spending again, or null if nothing is blocking.
     *
     * @return \DateTimeInterface|null
     */
    public function nextResetAt(): ?\DateTimeInterface
    {
        if ($this->headroom() > 0 || $this->purchased_credits > 0) {
            return null;
        }

        return $this->windows
            ->filter(fn (Window $w) => $this->plan()->creditsIn($w->key) >= 0)
            ->map(fn (Window $w) => $w->endsAt())
            ->sort()
            ->first();
    }

    /**
     * Take credits off the balance, plan allowance first.
     *
     * The two totals add to less than what was charged exactly when the account
     * overdrew, which is how an overdraft stays visible.
     *
     * @param int $credits
     * @return array{plan: int, purchased: int}
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
            if ($this->relationLoaded('meterable')) {
                $locked->setRelation('meterable', $this->getRelation('meterable'));
            }

            $locked->load('windows');

            $headroom = $locked->headroom();
            $fromPlan = $headroom === PHP_INT_MAX ? $credits : min($credits, $headroom);
            $overflow = $credits - $fromPlan;
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

    /**
     * Store a plan on the account.
     *
     * Windows are not restarted, so an upgrade raises the ceiling over what was already
     * spent rather than granting a second allowance.
     *
     * @param string|null $handle
     * @return void
     */
    public function setPlan(?string $handle): void
    {
        $this->plan = $handle;
        $this->cachedPlan = null;
        $this->save();
    }

    /**
     * Record credits in. The observer on Deposit moves the balance to match.
     *
     * @param int $credits
     * @param string $reason
     * @param Model|null $source
     * @param string|null $note
     * @param array<string, mixed> $metadata
     * @return Deposit
     */
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
