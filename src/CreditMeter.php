<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Charges an account and says whether it may spend more.
 *
 * Nothing here computes a balance: it writes a usage row, and the observer on that row
 * moves the account and its windows. Reading a balance is a row and its windows, never an
 * aggregate over the usage table.
 *
 * Most apps never touch this class. Put the HasCredits trait on whatever you bill and
 * call the methods it gives you.
 */
class CreditMeter
{
    /**
     * Memo of hasCreditsMemoized(), for as long as this instance lives.
     *
     * It is NOT invalidated on charging, deliberately. Otherwise an agentic loop would
     * re-read on every iteration, since every iteration records. The consequence is that
     * a turn which starts with credit finishes even if it runs out midway, which is what
     * you want: stopping halfway leaves the user a half-built answer, and the overshoot
     * is bounded to one turn.
     *
     * See LarameterServiceProvider for why this binding is scoped and not a singleton.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    // ─── Charging ───────────────────────────────────────────────────

    /** A fixed-price action: creating a form, sending an email, running an export. */
    public function charge(
        Model $meterable,
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
        array $metadata = [],
    ): UsageRecord {
        return UsageRecord::create([
            'account_id' => $this->account($meterable)->getKey(),
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

    /**
     * Metered consumption, priced per unit in and out.
     *
     * $operation is what gets priced: for an LLM call that is the model name, so the rate
     * table reads the way the providers publish theirs. $unit is only a label on the row,
     * so a bill can tell tokens from minutes.
     */
    public function meter(
        Model $meterable,
        string $operation,
        string $unit,
        int $quantityIn,
        int $quantityOut = 0,
        ?Model $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): UsageRecord {
        // Indexed directly, NOT through dot notation: a name with a dot in it (gpt-5.4)
        // would be split by config() and fall through to the wildcard, which undercharges
        // and only shows up on the provider's invoice.
        $rates = config('larameter.rates') ?? [];
        $rate = $rates[$operation] ?? $rates['*'] ?? null;

        return UsageRecord::create([
            'account_id' => $this->account($meterable)->getKey(),
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
        $prices = config('larameter.prices') ?? [];

        return (int) ($prices[$operation] ?? 0);
    }

    // ─── Balance ────────────────────────────────────────────────────

    public function account(Model $meterable): Account
    {
        return $meterable instanceof Account ? $meterable : Account::for($meterable);
    }

    /** What the PLAN still allows, in whichever window is tightest. Purchased not counted. */
    public function headroom(Model $meterable): int
    {
        return $this->account($meterable)->headroom();
    }

    /** The plan's allowance in one window, ignoring what has been spent of it. */
    public function allowanceIn(Model $meterable, string $window): int
    {
        return Plans::creditsIn($this->account($meterable)->plan_key, $window);
    }

    public function remaining(Model $meterable): int
    {
        return $this->account($meterable)->remaining();
    }

    public function hasCredits(Model $meterable, int $credits = 1): bool
    {
        return $this->account($meterable)->hasCredits($credits);
    }

    /** Same answer, cached for this instance. See the note on $memo. */
    public function hasCreditsMemoized(Model $meterable, int $credits = 1): bool
    {
        $key = $meterable->getMorphClass() . ':' . $meterable->getKey() . ':' . $credits;

        return $this->memo[$key] ??= $this->hasCredits($meterable, $credits);
    }

    // ─── Quantity caps ──────────────────────────────────────────────

    /**
     * How many more of something an account may create. A different question from
     * credits: seats and projects are ceilings, not spend.
     */
    public function canCreate(Model $meterable, string $resource, int $current): bool
    {
        $limit = Plans::limit($this->account($meterable)->plan_key, $resource);

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
