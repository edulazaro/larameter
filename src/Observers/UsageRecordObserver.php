<?php

namespace EduLazaro\Larameter\Observers;

use EduLazaro\Larameter\Models\UsageRecord;

/**
 * Keeps the balance in step with the rows.
 *
 * It is an observer rather than a line inside CreditMeter so that a usage row written by
 * anything at all still moves the balance: a backfill, a console command, an app that
 * prices something the package never hears about.
 */
class UsageRecordObserver
{
    public function created(UsageRecord $record): void
    {
        if ($record->credits <= 0) {
            return;
        }

        $record->account?->apply($record->credits);
    }
}
