<?php

namespace EduLazaro\Laracredits\Concerns;

use EduLazaro\Laracredits\CreditMeter;
use EduLazaro\Laracredits\Models\UsageRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Put this on whatever you bill: an organisation, a team, a user.
 *
 * With it, the whole setup is publishing the config, naming your plans and adding this
 * line. No interface to implement, nothing to bind.
 *
 *     $org->hasCredits();
 *     $org->chargeCredits('create_form');
 *     $org->creditsRemaining();
 */
trait HasCredits
{
    public function usageRecords(): MorphMany
    {
        return $this->morphMany(UsageRecord::class, 'account');
    }

    /**
     * Which plan this account is on. Reads a `plan` column by default; override it if
     * yours lives somewhere else, on a subscription row or behind a relation.
     */
    public function creditPlanKey(): string
    {
        return $this->getAttribute('plan') ?: (string) config('laracredits.default_plan', 'free');
    }

    public function hasCredits(int $credits = 1): bool
    {
        return $this->meter()->hasCredits($this, $credits);
    }

    public function creditsRemaining(): int
    {
        return $this->meter()->remaining($this);
    }

    public function creditBudget(): int
    {
        return $this->meter()->budget($this);
    }

    /** 'weekly', 'monthly', or null when there is room. */
    public function creditLimitHit(): ?string
    {
        return $this->meter()->limitHit($this);
    }

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

    /** How many more of a resource this account may create. Ceilings, not spend. */
    public function canCreate(string $resource, int $current): bool
    {
        return $this->meter()->canCreate($this, $resource, $current);
    }

    protected function meter(): CreditMeter
    {
        return app(CreditMeter::class);
    }
}
