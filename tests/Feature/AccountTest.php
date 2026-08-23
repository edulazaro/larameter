<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Account;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The balance. This is the part that has to be right: everything else only writes rows.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $plans = ['free' => ['credits_monthly' => 100]], ?string $default = 'free'): Organization
    {
        config()->set('larameter.plans', $plans);
        config()->set('larameter.default_plan', $default);

        return Organization::create(['name' => 'Acme']);
    }

    public function test_an_account_appears_on_first_sight_with_the_default_plan(): void
    {
        $org = $this->org();

        $this->assertNull($org->meterAccount()->first());

        $account = $org->creditAccount();

        $this->assertSame('free', $account->plan_key);
        $this->assertSame(100, $org->creditsRemaining());
    }

    public function test_the_balance_is_the_allowance_left_plus_what_was_bought(): void
    {
        $org = $this->org();
        $org->addCredits(50);

        $this->assertSame(150, $org->creditsRemaining());

        $org->chargeCredits('thing', credits: 30);

        $this->assertSame(120, $org->creditsRemaining());
    }

    public function test_the_allowance_is_spent_before_anything_purchased(): void
    {
        $org = $this->org();
        $org->addCredits(50);

        $org->chargeCredits('thing', credits: 100);

        $account = $org->creditAccount()->refresh();

        $this->assertSame(100, $account->period_credits_used);
        $this->assertSame(50, $account->purchased_credits, 'what they paid for should be untouched');
        $this->assertSame(50, $org->creditsRemaining());
    }

    public function test_purchased_credits_survive_the_period_reset_and_the_allowance_does_not(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->addCredits(50);
        $org->chargeCredits('thing', credits: 100);

        $this->assertSame(50, $org->creditsRemaining());

        Carbon::setTestNow('2026-02-11 09:00:00');

        // The allowance came back. The 50 they bought is still there.
        $this->assertSame(150, $org->creditAccount()->fresh()->remaining());
    }

    public function test_a_dormant_account_gets_one_allowance_back_and_not_four(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->chargeCredits('thing', credits: 100);

        Carbon::setTestNow('2026-05-10 09:00:00');

        $account = $org->creditAccount()->fresh();

        $this->assertSame(100, $account->remaining(), 'one period, not four');
        $this->assertSame('2026-05-10', $account->fresh()->period_started_at->toDateString());
    }

    public function test_credits_with_no_plan_at_all_is_a_valid_account(): void
    {
        $org = $this->org(plans: [], default: null);

        $this->assertNull($org->creditPlan());
        $this->assertSame(0, $org->creditsRemaining());
        $this->assertFalse($org->hasCredits());

        $org->addCredits(200);

        $this->assertSame(200, $org->creditsRemaining());
        $this->assertTrue($org->hasCredits());
    }

    public function test_an_unlimited_plan_never_eats_into_purchased_credits(): void
    {
        $org = $this->org(['free' => ['credits_monthly' => -1]]);
        $org->addCredits(50);

        $org->chargeCredits('thing', credits: 10_000);

        $account = $org->creditAccount()->refresh();

        $this->assertSame(50, $account->purchased_credits);
        $this->assertSame(10_000, $account->period_credits_used, 'still counted, so usage is reportable');
        $this->assertSame(PHP_INT_MAX, $org->creditsRemaining());
    }

    public function test_an_overdraft_clamps_at_zero_rather_than_storing_a_negative(): void
    {
        $org = $this->org();

        // A turn that starts with credit is allowed to finish, so overshooting is
        // expected. What must not happen is a balance that has to be understood as
        // a debt before it can be read.
        $org->chargeCredits('thing', credits: 150);

        $account = $org->creditAccount()->refresh();

        $this->assertSame(0, $account->purchased_credits);
        $this->assertSame(0, $org->creditsRemaining());
    }

    public function test_upgrading_mid_period_raises_the_ceiling_without_granting_a_second_allowance(): void
    {
        $org = $this->org([
            'free' => ['credits_monthly' => 100],
            'pro' => ['credits_monthly' => 1000],
        ]);

        $org->chargeCredits('thing', credits: 100);
        $this->assertSame(0, $org->creditsRemaining());

        $org->setCreditPlan('pro');

        // 1000 minus the 100 already spent. Upgrade-then-downgrade in one afternoon does
        // not hand out allowances.
        $this->assertSame(900, $org->creditAccount()->fresh()->remaining());
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
            'credits' => 40,
        ]);

        $this->assertSame(60, $org->creditsRemaining());
    }

    public function test_one_account_per_thing_billed(): void
    {
        $org = $this->org();

        $first = Account::for($org);
        $second = Account::for($org);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Account::count());
    }
}
