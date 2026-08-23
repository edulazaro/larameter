<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The balance. This is the part that has to be right: everything else only writes rows.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', [
            'session' => ['minutes' => 300, 'anchor' => 'rolling'],
            'week' => ['days' => 7, 'anchor' => 'fixed'],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits' => ['session' => 50, 'week' => 200]],
        ]);
        config()->set('larameter.default_plan', 'free');
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme']);
    }

    public function test_an_account_appears_on_first_sight_with_the_default_plan(): void
    {
        $org = $this->org();

        $this->assertNull($org->meterAccount()->first());

        $this->assertSame('free', $org->creditAccount()->plan_key);
        $this->assertSame(50, $org->creditsRemaining(), 'the tightest window');
    }

    public function test_the_tightest_window_is_the_one_that_binds(): void
    {
        $org = $this->org();

        $org->chargeCredits('thing', credits: 40);

        // Session had 50 and now has 10. The week had 200 and now has 160.
        $this->assertSame(10, $org->creditHeadroom());
    }

    public function test_the_balance_is_the_headroom_plus_what_was_bought(): void
    {
        $org = $this->org();
        $org->depositCredits(500);

        $this->assertSame(550, $org->creditsRemaining());

        $org->chargeCredits('thing', credits: 30);

        $this->assertSame(520, $org->creditsRemaining());
    }

    public function test_the_plan_allowance_is_spent_before_anything_purchased(): void
    {
        $org = $this->org();
        $org->depositCredits(500);

        $record = $org->chargeCredits('thing', credits: 50);

        $this->assertSame(50, $record->fresh()->credits_from_plan);
        $this->assertSame(0, $record->fresh()->credits_from_purchased);
        $this->assertSame(500, $org->creditAccount()->purchased_credits, 'what they paid for is untouched');
    }

    public function test_purchased_credits_are_not_counted_against_the_windows(): void
    {
        $org = $this->org();
        $org->depositCredits(1_000);

        $org->chargeCredits('burn the session', credits: 50);
        $org->chargeCredits('carry on paying', credits: 300);

        $account = $org->creditAccount();
        $windows = $account->windows()->get()->keyBy('key');

        // The 300 came out of the purchased bucket, so the week has not moved. This is
        // what makes topping up work the way people expect it to: you run out of session,
        // you buy more usage, you carry on, and your week is still where you left it.
        $this->assertSame(50, $windows['session']->credits_used);
        $this->assertSame(50, $windows['week']->credits_used);
        $this->assertSame(700, $account->purchased_credits);
    }

    public function test_a_window_missing_from_a_plans_credits_does_not_constrain_it(): void
    {
        config()->set('larameter.plans', [
            'weekly_only' => ['credits' => ['week' => 2_000]],
        ]);
        config()->set('larameter.default_plan', 'weekly_only');

        $org = $this->org();

        // Saying 'week' => 2000 and nothing else means limited weekly, session free.
        $this->assertSame(2_000, $org->creditHeadroom());
    }

    public function test_credits_with_no_plan_at_all_is_a_valid_account(): void
    {
        config()->set('larameter.default_plan', null);

        $org = $this->org();

        $this->assertNull($org->creditPlan());
        $this->assertSame(0, $org->creditsRemaining());
        $this->assertFalse($org->hasCredits());

        $org->depositCredits(200);

        $this->assertSame(200, $org->creditsRemaining());
        $this->assertTrue($org->hasCredits());
    }

    public function test_an_overdraft_clamps_at_zero_and_stays_visible_in_the_split(): void
    {
        $org = $this->org();

        // A turn that starts with credit is allowed to finish, so overshooting is
        // expected. What must not happen is a balance that has to be read as a debt.
        $record = $org->chargeCredits('thing', credits: 150)->fresh();

        $this->assertSame(0, $org->creditsRemaining());
        $this->assertSame(0, $org->creditAccount()->purchased_credits);

        $this->assertSame(150, $record->credits);
        $this->assertSame(50, $record->credits_from_plan);
        $this->assertSame(0, $record->credits_from_purchased);
        // 150 charged, 50 paid for: the missing 100 is the overdraft, still on the record.
    }

    public function test_upgrading_raises_the_ceiling_without_restarting_the_window(): void
    {
        config()->set('larameter.plans', [
            'free' => ['credits' => ['session' => 50, 'week' => 200]],
            'pro' => ['credits' => ['session' => 500, 'week' => 5_000]],
        ]);

        $org = $this->org();
        $org->chargeCredits('thing', credits: 50);

        $this->assertSame(0, $org->creditHeadroom());

        $org->setCreditPlan('pro');

        // 500 minus the 50 already spent this session. Upgrade-then-downgrade in one
        // afternoon does not hand out allowances.
        $this->assertSame(450, $org->creditHeadroom());
    }

    public function test_a_usage_row_written_by_anything_at_all_moves_the_balance(): void
    {
        $org = $this->org();
        $account = $org->creditAccount();

        // Not through the meter: a backfill, a console command, an app pricing something
        // the package never hears about.
        UsageRecord::create([
            'account_id' => $account->getKey(),
            'operation' => 'imported',
            'credits' => 20,
        ]);

        $this->assertSame(30, $org->creditsRemaining());
    }

    public function test_a_deposit_written_by_anything_at_all_moves_the_balance(): void
    {
        $org = $this->org();

        Deposit::create([
            'account_id' => $org->creditAccount()->getKey(),
            'credits' => 900,
            'reason' => 'gift',
        ]);

        $this->assertSame(950, $org->creditsRemaining());
    }

    public function test_an_adjustment_downwards_is_the_same_kind_of_row(): void
    {
        $org = $this->org();
        $org->depositCredits(500);

        $org->depositCredits(-200, reason: 'adjustment', note: 'duplicate purchase');

        $this->assertSame(300, $org->creditAccount()->purchased_credits);
        $this->assertSame(2, $org->creditDeposits()->count(), 'the history reads in one direction');

        // Clamped, not turned into a debt nobody can spend their way out of.
        $org->depositCredits(-9_999, reason: 'adjustment');

        $this->assertSame(0, $org->creditAccount()->purchased_credits);
    }

    public function test_a_deposit_records_where_it_came_from(): void
    {
        $org = $this->org();
        $payment = $this->org();

        $deposit = $org->depositCredits(100, reason: 'purchase', source: $payment, note: 'inv 1234');

        $this->assertTrue($deposit->source->is($payment));
        $this->assertSame('inv 1234', $deposit->note);
    }

    public function test_one_account_per_thing_billed(): void
    {
        $org = $this->org();

        $this->assertTrue(Account::for($org)->is(Account::for($org)));
        $this->assertSame(1, Account::count());
    }
}
