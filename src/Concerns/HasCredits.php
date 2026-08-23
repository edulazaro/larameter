<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\UsageTracker;
use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Put this on whatever you bill: an organisation, a team, a user, a workspace.
 *
 * That is the whole setup. Publish the config, name your plans, add this line. There is
 * no interface to implement, nothing to bind, and no column on your table.
 *
 *     $org->hasCredits();
 *     $org->chargeCredits('create_form');
 *     $org->meterCredits('gpt-4o', 'token', $in, $out);
 *     $org->creditsRemaining();
 *
 *     $org->setCreditPlan('pro');                        your subscription changed
 *     $org->depositCredits(5_000, reason: 'purchase');   they bought a bundle
 */
trait HasCredits
{
    /**
     * The account row, for eager loading. Null until this model has one.
     *
     * Use creditAccount() when you want it to exist.
     */
    public function meterAccount(): MorphOne
    {
        return $this->morphOne(Account::class, 'meterable');
    }

    /** The account, created on first sight. Nothing has to be provisioned up front. */
    public function creditAccount(): Account
    {
        return $this->relationLoaded('meterAccount') && $this->meterAccount
            ? $this->meterAccount
            : Account::for($this);
    }

    public function creditUsage()
    {
        return UsageRecord::where('account_id', $this->creditAccount()->getKey());
    }

    public function creditDeposits()
    {
        return Deposit::where('account_id', $this->creditAccount()->getKey());
    }

    // ─── Balance ────────────────────────────────────────────────────

    public function hasCredits(int $credits = 1): bool
    {
        return $this->usageTracker()->hasCredits($this, $credits);
    }

    /** Plan allowance left in the tightest window, plus anything bought on top. */
    public function creditsRemaining(): int
    {
        return $this->usageTracker()->remaining($this);
    }

    /** The same, without counting purchased credits. */
    public function creditHeadroom(): int
    {
        return $this->usageTracker()->headroom($this);
    }

    /** What the plan grants in one window, before anything is spent of it. */
    public function creditAllowanceIn(string $window): int
    {
        return $this->usageTracker()->allowanceIn($this, $window);
    }

    /** When they can spend again, or null if nothing is blocking them right now. */
    public function creditsResetAt(): ?\DateTimeInterface
    {
        return $this->creditAccount()->nextResetAt();
    }

    // ─── Charging ───────────────────────────────────────────────────

    public function chargeCredits(
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
    ): UsageRecord {
        return $this->usageTracker()->charge($this, $operation, $actor, $subject, $credits);
    }

    public function meterCredits(
        string $operation,
        string $unit,
        int $quantityIn,
        int $quantityOut = 0,
        ?Model $actor = null,
        ?Model $subject = null,
    ): UsageRecord {
        return $this->usageTracker()->meter($this, $operation, $unit, $quantityIn, $quantityOut, $actor, $subject);
    }

    // ─── Plan and top-ups ───────────────────────────────────────────

    public function creditPlan(): ?string
    {
        return $this->creditAccount()->plan_key;
    }

    /**
     * Move to a plan, or to none.
     *
     * The package cannot know when yours changes: Stripe, a manual override, a trial
     * quietly lapsing. Call this from wherever you do know. The windows are not
     * restarted, so an upgrade raises the ceiling rather than granting a second
     * allowance.
     */
    public function setCreditPlan(?string $key): void
    {
        $this->creditAccount()->setPlan($key);
    }

    /**
     * Line the billing windows up with a period that has just started.
     *
     * Call it when they first pay and on every renewal:
     *
     *     $org->startCreditPeriod($subscription->asStripeSubscription()->current_period_start);
     *
     * NEVER on a plan change. Upgrade, new allowance, downgrade, repeat is exactly the
     * door this keeps shut, and setCreditPlan() deliberately does not touch it.
     *
     * Rolling windows are left alone, and the same instant twice does nothing.
     */
    public function startCreditPeriod(?\DateTimeInterface $at = null): void
    {
        $this->creditAccount()->startPeriod($at);
    }

    /**
     * Credits in: a purchase, a gift, a refund, a correction. Negative is allowed and is
     * how an adjustment downwards is written.
     *
     * Writes the deposit AND moves the balance. They cannot be written apart.
     */
    public function depositCredits(
        int $credits,
        string $reason = 'purchase',
        ?Model $source = null,
        ?string $note = null,
        array $metadata = [],
    ): Deposit {
        return $this->creditAccount()->deposit($credits, $reason, $source, $note, $metadata);
    }

    // ─── Ceilings ───────────────────────────────────────────────────
    //
    // Ceilings live in HasMeters, which counts them for you. Add that trait alongside
    // this one and declare a meter per resource.

    protected function usageTracker(): UsageTracker
    {
        return app(UsageTracker::class);
    }
}
