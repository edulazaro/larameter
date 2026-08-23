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
            'session' => ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.25],
            'week' => ['days' => 7, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits_monthly' => 200],
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

        $this->assertSame('free', $org->plan()->handle);
        $this->assertSame(50, $org->credits()->remaining(), 'the tightest window');
    }

    public function test_the_tightest_window_is_the_one_that_binds(): void
    {
        $org = $this->org();

        $org->credits()->charge('thing', credits: 40);

        // Session had 50 and now has 10. The week had 200 and now has 160.
        $this->assertSame(10, $org->credits()->headroom());
    }

    public function test_the_balance_is_the_headroom_plus_what_was_bought(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500);

        $this->assertSame(550, $org->credits()->remaining());

        $org->credits()->charge('thing', credits: 30);

        $this->assertSame(520, $org->credits()->remaining());
    }

    public function test_the_plan_allowance_is_spent_before_anything_purchased(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500);

        $record = $org->credits()->charge('thing', credits: 50);

        $this->assertSame(50, $record->fresh()->credits_from_plan);
        $this->assertSame(0, $record->fresh()->credits_from_purchased);
        $this->assertSame(500, $org->credits()->account()->purchased_credits, 'what they paid for is untouched');
    }

    public function test_purchased_credits_are_not_counted_against_the_windows(): void
    {
        $org = $this->org();
        $org->credits()->deposit(1_000);

        $org->credits()->charge('burn the session', credits: 50);
        $org->credits()->charge('carry on paying', credits: 300);

        $account = $org->credits()->account();
        $windows = $account->windows()->get()->keyBy('key');

        // The 300 came out of the purchased bucket, so the week has not moved. This is
        // what makes topping up work the way people expect it to: you run out of session,
        // you buy more usage, you carry on, and your week is still where you left it.
        $this->assertSame(50, $windows['session']->credits_used);
        $this->assertSame(50, $windows['week']->credits_used);
        $this->assertSame(700, $account->purchased_credits);
    }

    public function test_a_window_with_no_share_does_not_narrow_the_allowance(): void
    {
        config()->set('larameter.windows', [
            'session' => ['minutes' => 300, 'anchor' => 'rolling'],
            'week' => ['days' => 7, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', [
            'weekly_only' => ['credits_monthly' => 2_000],
        ]);
        config()->set('larameter.default_plan', 'weekly_only');

        $org = Organization::create(['name' => 'Acme']);

        // The session declares no share, so it does not narrow anything and the week,
        // at the whole allowance, is what binds.
        $this->assertSame(2_000, $org->credits()->headroom());
    }

    public function test_credits_with_no_plan_at_all_is_a_valid_account(): void
    {
        config()->set('larameter.default_plan', null);

        $org = $this->org();

        $this->assertFalse($org->plan()->exists);
        $this->assertSame(0, $org->credits()->remaining());
        $this->assertFalse($org->credits()->allows());

        $org->credits()->deposit(200);

        $this->assertSame(200, $org->credits()->remaining());
        $this->assertTrue($org->credits()->allows());
    }

    public function test_an_overdraft_clamps_at_zero_and_stays_visible_in_the_split(): void
    {
        $org = $this->org();

        // A turn that starts with credit is allowed to finish, so overshooting is
        // expected. What must not happen is a balance that has to be read as a debt.
        $record = $org->credits()->charge('thing', credits: 150)->fresh();

        $this->assertSame(0, $org->credits()->remaining());
        $this->assertSame(0, $org->credits()->account()->purchased_credits);

        $this->assertSame(150, $record->credits);
        $this->assertSame(50, $record->credits_from_plan);
        $this->assertSame(0, $record->credits_from_purchased);
        // 150 charged, 50 paid for: the missing 100 is the overdraft, still on the record.
    }

    public function test_upgrading_raises_the_ceiling_without_restarting_the_window(): void
    {
        config()->set('larameter.plans', [
            'free' => ['credits_monthly' => 200],
            'pro' => ['credits_monthly' => 5_000],
        ]);

        $org = $this->org();
        $org->credits()->charge('thing', credits: 50);

        $this->assertSame(0, $org->credits()->headroom());

        $org->credits()->setPlan('pro');

        // A quarter of 5,000 is 1,250, minus the 50 already spent this session.
        // Upgrade-then-downgrade in one afternoon does not hand out allowances.
        $this->assertSame(1_200, $org->credits()->headroom());
    }

    public function test_a_usage_row_written_by_anything_at_all_moves_the_balance(): void
    {
        $org = $this->org();
        $account = $org->credits()->account();

        // Not through the meter: a backfill, a console command, an app pricing something
        // the package never hears about.
        UsageRecord::create([
            'account_id' => $account->getKey(),
            'operation' => 'imported',
            'credits' => 20,
        ]);

        $this->assertSame(30, $org->credits()->remaining());
    }

    public function test_a_deposit_written_by_anything_at_all_moves_the_balance(): void
    {
        $org = $this->org();

        Deposit::create([
            'account_id' => $org->credits()->account()->getKey(),
            'credits' => 900,
            'reason' => 'gift',
        ]);

        $this->assertSame(950, $org->credits()->remaining());
    }

    public function test_an_adjustment_downwards_is_the_same_kind_of_row(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500);

        $org->credits()->deposit(-200, reason: 'adjustment', note: 'duplicate purchase');

        $this->assertSame(300, $org->credits()->account()->purchased_credits);
        $this->assertSame(2, $org->credits()->deposits()->count(), 'the history reads in one direction');

        // Clamped, not turned into a debt nobody can spend their way out of.
        $org->credits()->deposit(-9_999, reason: 'adjustment');

        $this->assertSame(0, $org->credits()->account()->purchased_credits);
    }

    public function test_a_deposit_records_where_it_came_from(): void
    {
        $org = $this->org();
        $payment = $this->org();

        $deposit = $org->credits()->deposit(100, reason: 'purchase', source: $payment, note: 'inv 1234');

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
