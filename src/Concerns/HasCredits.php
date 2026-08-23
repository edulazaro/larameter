<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\CreditMeter;
use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Put this on whatever you bill: an organisation, a team, a user, a workspace.
 *
 * That is the whole setup. Publish the config, name your plans, add this line. There is
 * no interface to implement and nothing to bind.
 *
 *     $org->hasCredits();
 *     $org->chargeCredits('create_form');
 *     $org->meterCredits('gpt-4o', 'token', $in, $out);
 *     $org->creditsRemaining();
 *
 *     $org->setCreditPlan('pro');   // when your subscription changes
 *     $org->addCredits(5_000);      // when somebody buys a bundle
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

    // ─── Balance ────────────────────────────────────────────────────

    public function hasCredits(int $credits = 1): bool
    {
        return $this->meter()->hasCredits($this, $credits);
    }

    /** Allowance left this period, plus anything bought on top. */
    public function creditsRemaining(): int
    {
        return $this->meter()->remaining($this);
    }

    /** What the plan grants per period, before purchased credits. */
    public function creditBudget(): int
    {
        return $this->meter()->budget($this);
    }

    // ─── Charging ───────────────────────────────────────────────────

    public function chargeCredits(
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
    ): UsageRecord {
        return $this->meter()->charge($this, $operation, $actor, $subject, $credits);
    }

    public function meterCredits(
        string $operation,
        string $unit,
        int $quantityIn,
        int $quantityOut = 0,
        ?Model $actor = null,
        ?Model $subject = null,
    ): UsageRecord {
        return $this->meter()->meter($this, $operation, $unit, $quantityIn, $quantityOut, $actor, $subject);
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
     * quietly lapsing. Call this from wherever you do know. The period is not restarted,
     * so an upgrade raises the ceiling rather than granting a second allowance.
     */
    public function setCreditPlan(?string $key): void
    {
        $this->creditAccount()->setPlan($key);
    }

    /** Credits bought. These accumulate and survive the period reset. */
    public function addCredits(int $credits): void
    {
        $this->creditAccount()->addCredits($credits);
    }

    // ─── Ceilings ───────────────────────────────────────────────────

    /** How many more of a resource this may create. Ceilings, not spend. */
    public function canCreate(string $resource, int $current): bool
    {
        return $this->meter()->canCreate($this, $resource, $current);
    }

    protected function meter(): CreditMeter
    {
        return app(CreditMeter::class);
    }
}
