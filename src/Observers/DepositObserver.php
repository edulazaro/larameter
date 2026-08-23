<?php

namespace EduLazaro\Larameter\Observers;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use Illuminate\Support\Facades\DB;

/**
 * Moves purchased_credits to match the deposit that was just written.
 *
 * The column is a cache of this table. Keeping it in an observer means a deposit created
 * by any route at all lands on the balance, and that the two can never be written apart.
 */
class DepositObserver
{
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

            // Clamped: an adjustment downwards larger than the balance takes it to zero
            // rather than into a debt nobody can spend their way out of.
            $account->purchased_credits = max(0, $account->purchased_credits + $deposit->credits);
            $account->save();
        });
    }
}
