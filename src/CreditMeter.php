<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Contracts\ProvidesPlanLimits;
use EduLazaro\Larameter\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Charges an account and says whether it may spend more.
 *
 * Two windows, and the tighter one wins. The monthly budget is what you sell; the weekly
 * is a brake, defaulting to a quarter of it. Without the second, an account can burn a
 * month of allowance in a bad afternoon and spend the rest of it locked out, which reads
 * as the product being broken rather than as the plan being small.
 */
class CreditMeter
{
    /**
     * Memo of hasCredits(), for as long as this instance lives.
     *
     * The balance is two aggregates, and an agentic loop asks before every iteration:
     * without this that is a dozen queries on the hot path of one answer.
     *
     * It is NOT invalidated on recording, deliberately. Otherwise every iteration would
     * query again, since every iteration records. The consequence is that a turn which
     * starts with credit finishes even if it runs out midway, which is what you want:
     * stopping halfway leaves the user a half-built answer, and the overshoot is bounded
     * to a single turn.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    public function __construct(
        protected ProvidesPlanLimits $plan,
    ) {}

    // ─── Charging ───────────────────────────────────────────────────

    /** A fixed-price action: creating a form, sending an email, a minute of video. */
    public function charge(
        Model $account,
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
        array $metadata = [],
    ): UsageRecord {
        return UsageRecord::create([
            'account_type' => $account->getMorphClass(),
            'account_id' => $account->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'operation' => $operation,
            'unit' => 'action',
            'credits' => $credits ?? $this->priceOf($operation),
            'metadata' => $metadata ?: null,
        ]);
    }

    /** Metered consumption, priced per unit in and out. Tokens, minutes, pages. */
    public function meter(
        Model $account,
        string $operation,
        string $unit,
        int $quantityIn,
        int $quantityOut = 0,
        ?Model $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): UsageRecord {
        $rates = config("larameter.rates.{$unit}") ?? [];
        $rate = $rates[$operation] ?? $rates['*'] ?? null;

        return UsageRecord::create([
            'account_type' => $account->getMorphClass(),
            'account_id' => $account->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'operation' => $operation,
            'unit' => $unit,
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'credits' => $this->creditsFor($rate, $quantityIn, $quantityOut),
            'cost' => $this->costFor($rate, $quantityIn, $quantityOut),
            'metadata' => $metadata ?: null,
        ]);
    }

    /** What a fixed action costs. Unpriced actions are free rather than a guess. */
    public function priceOf(string $operation): int
    {
        return (int) config("larameter.prices.{$operation}", 0);
    }

    // ─── Balance ────────────────────────────────────────────────────

    public function budget(Model $account): int
    {
        return $this->plan->limitFor($account, 'credits_monthly');
    }

    public function weeklyBudget(Model $account): int
    {
        $monthly = $this->budget($account);

        if ($monthly < 0) {
            return $monthly;
        }

        $share = (float) config('larameter.weekly_share', 0.25);

        return (int) ceil($monthly * $share);
    }

    public function usedSince(Model $account, \DateTimeInterface $since): int
    {
        return (int) UsageRecord::where('account_type', $account->getMorphClass())
            ->where('account_id', $account->getKey())
            ->where('created_at', '>=', $since)
            ->sum('credits');
    }

    /** Whatever is tighter, the month or the week. */
    public function remaining(Model $account): int
    {
        $monthly = $this->budget($account);

        if ($monthly < 0) {
            return PHP_INT_MAX;
        }

        return min(
            max(0, $monthly - $this->usedSince($account, now()->startOfMonth())),
            max(0, $this->weeklyBudget($account) - $this->usedSince($account, now()->startOfWeek())),
        );
    }

    public function hasCredits(Model $account, int $credits = 1): bool
    {
        return $this->remaining($account) >= $credits;
    }

    /** Same answer, cached for this instance. See the note on $memo. */
    public function hasCreditsMemoized(Model $account, int $credits = 1): bool
    {
        $key = $account->getMorphClass() . ':' . $account->getKey() . ':' . $credits;

        return $this->memo[$key] ??= $this->hasCredits($account, $credits);
    }

    /** Which ceiling was hit, for telling the user something useful. */
    public function limitHit(Model $account): ?string
    {
        if ($this->budget($account) < 0) {
            return null;
        }

        if ($this->usedSince($account, now()->startOfWeek()) >= $this->weeklyBudget($account)) {
            return 'weekly';
        }

        return $this->usedSince($account, now()->startOfMonth()) >= $this->budget($account)
            ? 'monthly'
            : null;
    }

    // ─── Quantity caps ──────────────────────────────────────────────

    /**
     * How many more of something an account may create. A different question from
     * credits: seats and projects are ceilings, not spend.
     */
    public function canCreate(Model $account, string $resource, int $current): bool
    {
        $limit = $this->plan->limitFor($account, $resource);

        return $limit < 0 || $current < $limit;
    }

    // ─── Pricing ────────────────────────────────────────────────────

    private function creditsFor(?array $rate, int $in, int $out): int
    {
        if (! $rate) {
            // Unpriced units still cost something, or metering an unknown model would be
            // free and the gap would only show up on the provider's invoice.
            return max(1, (int) ceil(($in + $out) / (int) config('larameter.fallback_units_per_credit', 100)));
        }

        return max(1, (int) ceil($this->costFor($rate, $in, $out) * (int) config('larameter.credits_per_unit_cost', 10000)));
    }

    private function costFor(?array $rate, int $in, int $out): float
    {
        if (! $rate) {
            return 0.0;
        }

        $per = (int) ($rate['per'] ?? 1_000_000);

        return ($in / $per) * (float) ($rate['in'] ?? 0)
            + ($out / $per) * (float) ($rate['out'] ?? 0);
    }
}
