<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Charges an account and answers whether it may spend more.
 *
 * Nothing here computes a balance: it writes a usage row and the observer on that row
 * moves the account and its windows. Reading a balance is a row and its windows, never
 * an aggregate over the usage table.
 *
 * Most apps never touch this class. Put the HasCredits trait on whatever you bill and
 * call the methods it gives you.
 */
class UsageTracker
{
    /** @var array<string, bool> Memo of hasCreditsMemoized(), for the life of this instance. */
    private array $memo = [];

    /**
     * Charge a fixed-price action.
     *
     * @param Model $meterable
     * @param string $operation
     * @param Model|null $actor
     * @param Model|null $subject
     * @param int|null $credits
     * @param array<string, mixed> $metadata
     * @return UsageRecord
     */
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
     * Charge metered consumption, priced per unit in and out.
     *
     * The operation is what gets priced: for a model call that is the model name, so the
     * rate table reads the way providers publish theirs. The unit is only a label.
     *
     * `$cached` is the input a prompt cache served or stored, which providers price apart
     * from the rest: ['read' => …, 'write_5m' => …, 'write_1h' => …], each priced at the
     * rate's cache_read, cache_write_5m and cache_write_1h. `$quantityIn` is then only
     * the input that was not cached. The row keeps the split in its metadata.
     *
     * @param Model $meterable
     * @param string $operation
     * @param string $unit
     * @param int $quantityIn
     * @param int $quantityOut
     * @param Model|null $actor
     * @param Model|null $subject
     * @param array<string, mixed> $metadata
     * @param array<string, int> $cached
     * @return UsageRecord
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
        array $cached = [],
    ): UsageRecord {
        $rates = config('larameter.rates') ?? [];
        $rate = $rates[$operation] ?? $rates['*'] ?? null;
        $cached = $this->cachedQuantities($cached);

        if ($cached) {
            $metadata['cached'] = $cached;
        }

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
            'credits' => $this->creditsFor($rate, $quantityIn, $quantityOut, $cached),
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * What a metered quantity would cost, without charging it.
     *
     * The sibling of priceOf(): one prices an action, this prices a consumption. Needed
     * wherever a caller has to show or store the cost of a call the client already
     * charged, without recomputing the rate table by hand.
     *
     * @param string $operation
     * @param int $quantityIn
     * @param int $quantityOut
     * @param array<string, int> $cached
     * @return int
     */
    public function meteredPriceOf(string $operation, int $quantityIn, int $quantityOut = 0, array $cached = []): int
    {
        $rates = config('larameter.rates') ?? [];

        return $this->creditsFor($rates[$operation] ?? $rates['*'] ?? null, $quantityIn, $quantityOut, $this->cachedQuantities($cached));
    }

    /**
     * What a fixed action costs. Unpriced actions are free rather than guessed at.
     *
     * @param string $operation
     * @return int
     */
    public function priceOf(string $operation): int
    {
        $prices = config('larameter.prices') ?? [];

        return (int) ($prices[$operation] ?? 0);
    }

    /**
     * The credit account of a model.
     *
     * @param Model $meterable
     * @return Account
     */
    public function account(Model $meterable): Account
    {
        if ($meterable instanceof Account) {
            return $meterable;
        }

        if (method_exists($meterable, 'credits')) {
            return $meterable->credits()->account();
        }

        return Account::for($meterable);
    }

    /**
     * What the plan still allows, in whichever window is tightest.
     *
     * @param Model $meterable
     * @return int
     */
    public function headroom(Model $meterable): int
    {
        return $this->account($meterable)->headroom();
    }

    /**
     * The plan's allowance in one window, before anything is spent of it.
     *
     * @param Model $meterable
     * @param string $window
     * @return int
     */
    public function allowanceIn(Model $meterable, string $window): int
    {
        return $this->account($meterable)->plan()->creditsIn($window);
    }

    /**
     * Allowance left plus anything purchased.
     *
     * @param Model $meterable
     * @return int
     */
    public function remaining(Model $meterable): int
    {
        return $this->account($meterable)->remaining();
    }

    /**
     * Whether there is enough left to spend.
     *
     * @param Model $meterable
     * @param int $credits
     * @return bool
     */
    public function hasCredits(Model $meterable, int $credits = 1): bool
    {
        return $this->account($meterable)->hasCredits($credits);
    }

    /**
     * The same answer, cached for the life of this instance.
     *
     * @param Model $meterable
     * @param int $credits
     * @return bool
     */
    public function hasCreditsMemoized(Model $meterable, int $credits = 1): bool
    {
        $key = $meterable->getMorphClass().':'.$meterable->getKey().':'.$credits;

        return $this->memo[$key] ??= $this->hasCredits($meterable, $credits);
    }

    /**
     * Credits for a quantity, at the given rate. Rates are per million units.
     *
     * @param array<string, int>|null $rate
     * @param int $in
     * @param int $out
     * @return int
     */
    private function creditsFor(?array $rate, int $in, int $out, array $cached = []): int
    {
        if (! $rate) {
            return max(1, (int) ceil(($in + $out + array_sum($cached)) / (int) config('larameter.fallback_units_per_credit', 100)));
        }

        $credits = ($in / 1_000_000) * (float) ($rate['input'] ?? 0)
            + ($out / 1_000_000) * (float) ($rate['output'] ?? 0);

        // A cache price the table leaves out is the input price: never a discount nobody wrote down.
        foreach ($cached as $kind => $quantity) {
            $credits += ($quantity / 1_000_000) * (float) ($rate['cache_' . $kind] ?? $rate['input'] ?? 0);
        }

        return max(1, (int) ceil($credits));
    }

    /**
     * The cached quantities a caller passed, without the zeros. A kind the rate table has
     * no name for throws: priced as nothing, it would quietly undercharge.
     *
     * @param array<string, int> $cached
     * @return array<string, int>
     */
    private function cachedQuantities(array $cached): array
    {
        $unknown = array_diff(array_keys($cached), ['read', 'write_5m', 'write_1h']);

        if ($unknown) {
            throw new \InvalidArgumentException('Unknown cached quantity: ' . implode(', ', $unknown) . '. Use read, write_5m or write_1h.');
        }

        return array_filter(array_map('intval', $cached));
    }
}
