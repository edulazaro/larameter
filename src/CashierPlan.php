<?php

namespace EduLazaro\Larameter;

use Illuminate\Support\Carbon;

/**
 * A plan that came from a live subscription, and knows it.
 *
 * Everything a plain Plan answers, it answers the same: credits, limits and features all
 * come from your config regardless of where the plan was resolved. What it adds is where
 * it came from, which is what lets the billing windows line up with the invoice instead
 * of with whenever somebody first used the thing.
 *
 * Nothing should ever need `instanceof CashierPlan`. If it does, Plan is missing a
 * method rather than the caller needing to know the source.
 */
class CashierPlan extends Plan
{
    /** @param array<string, mixed> $config */
    public function __construct(
        string $key,
        array $config = [],
        protected mixed $subscription = null,
    ) {
        parent::__construct($key, $config);
    }

    /** The Cashier subscription, or null. Typed loosely so the package needs no Cashier. */
    public function subscription(): mixed
    {
        return $this->subscription;
    }

    /**
     * When the current billing period ends, which is when the next one starts.
     *
     * This is what larameter anchors its windows to. Without it the grid is laid on first
     * use, so somebody who tried the app on Tuesday and paid on Thursday gets a fresh
     * allowance five days after paying, every month.
     */
    public function renewsAt(): ?Carbon
    {
        $ends = $this->stripeValue('current_period_end');

        return $ends ? Carbon::createFromTimestamp($ends) : null;
    }

    public function startedAt(): ?Carbon
    {
        $starts = $this->stripeValue('current_period_start');

        return $starts ? Carbon::createFromTimestamp($starts) : null;
    }

    public function onTrial(): bool
    {
        return (bool) ($this->subscription?->onTrial() ?? false);
    }

    public function cancelled(): bool
    {
        return (bool) ($this->subscription?->canceled() ?? false);
    }

    /**
     * Reads a field off the Stripe object behind the subscription.
     *
     * Cashier's own table does not store the period dates, so they are only available by
     * asking Stripe. Guarded, and null on any failure: a plan must still be readable when
     * the network is down, and everything above degrades to "no date" rather than
     * throwing in the middle of a page.
     */
    protected function stripeValue(string $field): ?int
    {
        if (! $this->subscription || ! method_exists($this->subscription, 'asStripeSubscription')) {
            return null;
        }

        try {
            return $this->subscription->asStripeSubscription()->{$field} ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
