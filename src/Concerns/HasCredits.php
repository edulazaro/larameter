<?php

namespace EduLazaro\Larameter\Concerns;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\UsageTracker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Put this on whatever you bill: an organisation, a team, a user, a workspace.
 *
 * That is the whole setup. Publish the config, name your plans, add this line: no
 * interface to implement, nothing to bind, and no column on your own table.
 *
 * Enough on its own for an app that sells bundles of credits. Add HasPlans for an
 * allowance and HasMeters for ceilings.
 */
trait HasCredits
{
    /**
     * The account row, for eager loading. Null until the model has one.
     *
     * @return MorphOne
     */
    public function meterAccount(): MorphOne
    {
        return $this->morphOne(Account::class, 'meterable');
    }

    /**
     * The account, created on first sight.
     *
     * @return Account
     */
    public function creditAccount(): Account
    {
        $account = $this->relationLoaded('meterAccount') && $this->meterAccount
            ? $this->meterAccount
            : Account::for($this);
        if (! $account->relationLoaded('meterable')) {
            $account->setRelation('meterable', $this);
        }

        return $account;
    }

    /**
     * Query of everything charged to this account.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function creditUsage()
    {
        return UsageRecord::where('account_id', $this->creditAccount()->getKey());
    }

    /**
     * Query of everything credited to this account.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function creditDeposits()
    {
        return Deposit::where('account_id', $this->creditAccount()->getKey());
    }

    /**
     * Whether there is enough left to spend.
     *
     * @param  int  $credits
     * @return bool
     */
    public function hasCredits(int $credits = 1): bool
    {
        return $this->usageTracker()->hasCredits($this, $credits);
    }

    /**
     * Allowance left in the tightest window, plus anything purchased.
     *
     * @return int
     */
    public function creditsRemaining(): int
    {
        return $this->usageTracker()->remaining($this);
    }

    /**
     * The same, without counting purchased credits.
     *
     * @return int
     */
    public function creditHeadroom(): int
    {
        return $this->usageTracker()->headroom($this);
    }

    /**
     * What the plan grants in one window, before anything is spent.
     *
     * @param  string  $window
     * @return int
     */
    public function creditAllowanceIn(string $window): int
    {
        return $this->usageTracker()->allowanceIn($this, $window);
    }

    /**
     * When spending becomes possible again, or null if nothing is blocking.
     *
     * @return \DateTimeInterface|null
     */
    public function creditsResetAt(): ?\DateTimeInterface
    {
        return $this->creditAccount()->nextResetAt();
    }

    /**
     * Charge a fixed-price action.
     *
     * @param  string  $operation
     * @param  Model|null  $actor
     * @param  Model|null  $subject
     * @param  int|null  $credits
     * @return UsageRecord
     */
    public function chargeCredits(
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
    ): UsageRecord {
        return $this->usageTracker()->charge($this, $operation, $actor, $subject, $credits);
    }

    /**
     * Charge metered consumption, priced per unit in and out.
     *
     * @param  string  $operation
     * @param  string  $unit
     * @param  int  $quantityIn
     * @param  int  $quantityOut
     * @param  Model|null  $actor
     * @param  Model|null  $subject
     * @return UsageRecord
     */
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

    /**
     * Store a plan on the account.
     *
     * A fallback, not the source: with HasPlans the plan is resolved, and this is only
     * reached when no provider answers.
     *
     * @param  string|null  $handle
     * @return void
     */
    public function setCreditPlan(?string $handle): void
    {
        $this->creditAccount()->setPlan($handle);

        if (method_exists($this, 'forgetPlan')) {
            $this->forgetPlan();
        }
    }

    /**
     * Line the fixed billing windows up with a period that has just started.
     *
     * Call it on first payment and on renewal. Never on a plan change: upgrade, new
     * allowance, downgrade, repeat is the door this keeps shut.
     *
     * @param  \DateTimeInterface|null  $at
     * @return void
     */
    public function startCreditPeriod(?\DateTimeInterface $at = null): void
    {
        $this->creditAccount()->startPeriod($at);
    }

    /**
     * Credits in: a purchase, a gift, a refund, a correction. Negative is allowed.
     *
     * @param  int  $credits
     * @param  string  $reason
     * @param  Model|null  $source
     * @param  string|null  $note
     * @param  array<string, mixed>  $metadata
     * @return Deposit
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

    /**
     * The tracker that does the charging.
     *
     * @return UsageTracker
     */
    protected function usageTracker(): UsageTracker
    {
        return app(UsageTracker::class);
    }
}
