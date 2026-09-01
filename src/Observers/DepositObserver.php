<?php

namespace EduLazaro\Larameter\Observers;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use Illuminate\Support\Facades\DB;

/**
 * Takes a refund out of the balance. Nothing else needs doing.
 *
 * A positive deposit is a lot, and a lot IS the credits it added: the balance reads it
 * where it lies. Moving the column as well would count it twice, once as a row and once
 * as a number, and every balance in the application would be double.
 *
 * So the column never grows again. It holds what was there before lots existed, it can
 * only drain, and the day it reaches zero it can be dropped.
 *
 * A NEGATIVE deposit is a refund or a correction, which is spending wearing a different
 * hat: out of the lots first, and out of the column for whatever the lots could not
 * cover.
 *
 * Being an observer means a row written by any route at all is handled: a backfill, a
 * console command, an application writing the deposit itself.
 */
class DepositObserver
{
    /**
     * Move the balance to match a deposit that was just written.
     *
     * @param Deposit $deposit
     * @return void
     */
    public function created(Deposit $deposit): void
    {
        if ($deposit->credits === 0) {
            return;
        }

        DB::transaction(function () use ($deposit) {
            $account = Account::query()->lockForUpdate()->find($deposit->account_id);

            if (! $account) {
                return;
            }
            // A positive deposit is already the balance it added. Nothing to move.
            if ($deposit->credits >= 0) {
                return;
            }

            $owed = abs($deposit->credits);
            $left = $owed - $account->takeFromLots($owed);

            if ($left <= 0) {
                return;
            }

            // getAttributes() and not the property: reading the property calls the
            // accessor, which already counts the lots.
            $stored = (int) ($account->getAttributes()['purchased_credits'] ?? 0);

            $account->setAttribute('purchased_credits', max(0, $stored - $left));
            $account->save();
        });
    }
}
