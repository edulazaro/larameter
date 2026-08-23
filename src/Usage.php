<?php

namespace EduLazaro\Larameter;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\Models\Window;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything to do with credits, for one thing you bill.
 *
 * Reached through the model, as $org->usage(). A door rather than a dozen methods
 * flattened into somebody else's class: a trait that claims common words collides with
 * whatever else that model already uses, and a collision between traits is a fatal
 * error when the class is compiled, not something the host app can catch.
 *
 * Its sisters are $org->plan(), which answers what was bought, and $org->quota(),
 * which answers how many of something may exist.
 */
class Usage
{
    /**
     * Create the credit view of a model.
     *
     * @param Model $meterable
     * @return void
     */
    public function __construct(protected Model $meterable)
    {
    }

    /**
     * The account, created on first sight.
     *
     * Not memoised: the observer that moves the balance works on its own copy of the
     * row, so holding one here would answer with a balance from before the last charge.
     *
     * @return Account
     */
    public function account(): Account
    {
        $account = $this->meterable->relationLoaded('meterAccount') && $this->meterable->meterAccount
            ? $this->meterable->meterAccount
            : Account::for($this->meterable);

        // So that reading the plan does not go back for the model it was reached from.
        if (! $account->relationLoaded('meterable')) {
            $account->setRelation('meterable', $this->meterable);
        }

        return $account;
    }

    /**
     * Whether there is enough left: for a number of credits, or for what an action costs.
     *
     * Charging does not refuse. An account with nothing left still gets its usage row,
     * recorded as an overdraft, because a half-finished turn is worse than a small
     * negative. So asking first has to be the easy thing to write.
     *
     * @param string|int $what An operation to price, or a number of credits.
     * @return bool
     */
    public function allows(string|int $what = 1): bool
    {
        $credits = is_string($what) ? $this->price($what) : $what;

        return $this->account()->hasCredits($credits);
    }

    /**
     * What a fixed-price action costs. Unpriced actions are free.
     *
     * @param string $operation
     * @return int
     */
    public function price(string $operation): int
    {
        return $this->tracker()->priceOf($operation);
    }

    /**
     * Allowance left in the tightest window, plus anything purchased.
     *
     * @return int
     */
    public function remaining(): int
    {
        return $this->account()->remaining();
    }

    /**
     * The same, without counting purchased credits.
     *
     * @return int
     */
    public function headroom(): int
    {
        return $this->account()->headroom();
    }

    /**
     * What the plan grants in one window, before anything is spent of it.
     *
     * @param string $window
     * @return int
     */
    public function allowanceIn(string $window): int
    {
        return $this->account()->plan()->creditsIn($window);
    }

    /**
     * One window: what the plan grants there, what has gone, and when it starts over.
     *
     * @param string $window
     * @return WindowUsage
     * @throws \InvalidArgumentException When no such window is declared.
     */
    public function in(string $window): WindowUsage
    {
        $windows = $this->windows();

        if (! array_key_exists($window, $windows)) {
            $declared = implode(', ', array_keys(Window::declared())) ?: 'none';

            throw new \InvalidArgumentException(
                "larameter: no window [{$window}] is declared. There is: {$declared}.",
            );
        }

        return $windows[$window];
    }

    /**
     * Every declared window, keyed by its key. What a usage screen iterates.
     *
     * Opens nothing: a window nobody has spent against yet reports its full allowance
     * and no end date, rather than having its clock started by being looked at.
     *
     * @return array<string, WindowUsage>
     */
    public function windows(): array
    {
        $account = $this->account();
        $rows = $account->windows->keyBy('key');
        $plan = $account->plan();

        $windows = [];

        foreach (array_keys(Window::declared()) as $key) {
            $row = $rows->get($key);

            $windows[$key] = new WindowUsage(
                $key,
                $plan->creditsIn($key),
                $row?->currentUsage() ?? 0,
                $row?->currentEndsAt(),
            );
        }

        return $windows;
    }

    /**
     * When spending becomes possible again, or null if nothing is blocking.
     *
     * @return \DateTimeInterface|null
     */
    public function resetsAt(): ?\DateTimeInterface
    {
        return $this->account()->nextResetAt();
    }

    /**
     * Charge a fixed-price action.
     *
     * @param string $operation
     * @param Model|null $actor
     * @param Model|null $subject
     * @param int|null $credits
     * @param array<string, mixed> $metadata
     * @return UsageRecord
     */
    public function charge(
        string $operation,
        ?Model $actor = null,
        ?Model $subject = null,
        ?int $credits = null,
        array $metadata = [],
    ): UsageRecord {
        return $this->tracker()->charge($this->meterable, $operation, $actor, $subject, $credits, $metadata);
    }

    /**
     * Charge metered consumption, priced per unit in and out.
     *
     * @param string $operation
     * @param string $unit
     * @param int $quantityIn
     * @param int $quantityOut
     * @param Model|null $actor
     * @param Model|null $subject
     * @param array<string, mixed> $metadata
     * @return UsageRecord
     */
    public function meter(
        string $operation,
        string $unit,
        int $quantityIn,
        int $quantityOut = 0,
        ?Model $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): UsageRecord {
        return $this->tracker()->meter(
            $this->meterable,
            $operation,
            $unit,
            $quantityIn,
            $quantityOut,
            $actor,
            $subject,
            $metadata,
        );
    }

    /**
     * Credits in: a purchase, a gift, a refund, a correction. Negative is allowed.
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
        return $this->account()->deposit($credits, $reason, $source, $note, $metadata);
    }

    /**
     * Query of everything charged to this account.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function records()
    {
        return UsageRecord::where('account_id', $this->account()->getKey());
    }

    /**
     * Query of everything credited to this account.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function deposits()
    {
        return Deposit::where('account_id', $this->account()->getKey());
    }

    /**
     * Store a plan on the account.
     *
     * A fallback, not the source: with HasPlans the plan is resolved, and this is only
     * reached when no provider answers.
     *
     * @param string|null $handle
     * @return void
     */
    public function setPlan(?string $handle): void
    {
        $this->account()->setPlan($handle);

        if (method_exists($this->meterable, 'forgetPlan')) {
            $this->meterable->forgetPlan();
        }
    }

    /**
     * The tracker that does the charging.
     *
     * @return UsageTracker
     */
    protected function tracker(): UsageTracker
    {
        return app(UsageTracker::class);
    }
}
