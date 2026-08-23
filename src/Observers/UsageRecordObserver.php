<?php

namespace EduLazaro\Larameter\Observers;

use EduLazaro\Larameter\Models\UsageRecord;

/**
 * Keeps the balance in step with the rows.
 *
 * An observer rather than a line inside UsageTracker, so that a usage row written by
 * anything at all still moves the balance: a backfill, a console command, an app pricing
 * something the package never hears about.
 */
class UsageRecordObserver
{
    /**
     * Take the charged credits off the balance, and record where they came from.
     *
     * @param UsageRecord $record
     * @return void
     */
    public function created(UsageRecord $record): void
    {
        if ($record->credits <= 0) {
            return;
        }

        $account = $record->account;

        if (! $account) {
            return;
        }

        $split = $account->apply($record->credits);

        // Quietly: this is the same row being finished off, not a new event to observe.
        $record->forceFill([
            'credits_from_plan' => $split['plan'],
            'credits_from_purchased' => $split['purchased'],
        ])->saveQuietly();
    }
}
