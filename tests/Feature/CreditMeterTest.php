<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Contracts\ProvidesPlanLimits;
use EduLazaro\Larameter\CreditMeter;
use EduLazaro\Larameter\Plans\ConfigPlanLimits;
use EduLazaro\Larameter\Tests\Fixtures\Account;
use EduLazaro\Larameter\Tests\Fixtures\FixedPlan;
use EduLazaro\Larameter\Tests\TestCase;

class CreditMeterTest extends TestCase
{
    private function meter(array $limits = []): CreditMeter
    {
        return new CreditMeter(new FixedPlan($limits));
    }

    private function account(): Account
    {
        return Account::create();
    }

    public function test_plans_come_from_config_with_no_interface_to_implement(): void
    {
        // The whole setup: publish the config, name your plans. Requiring an interface
        // first is friction in the minute someone decides whether to install this at all.
        config(['larameter.plans.free.credits_monthly' => 500]);

        $meter = $this->app->make(CreditMeter::class);
        $account = $this->account();

        $this->assertInstanceOf(ConfigPlanLimits::class, $this->app->make(ProvidesPlanLimits::class));
        $this->assertSame(500, $meter->budget($account));
        $this->assertTrue($meter->hasCredits($account, 100));
    }

    public function test_a_limit_you_never_listed_is_unlimited_not_zero(): void
    {
        // Backwards, and a package you just installed starts refusing things you never
        // meant to limit.
        $meter = $this->app->make(CreditMeter::class);

        $this->assertTrue($meter->canCreate($this->account(), 'a_resource_nobody_capped', 99999));
    }

    public function test_a_fixed_action_costs_what_you_priced_it_at(): void
    {
        config(['larameter.prices.create_form' => 3]);
        $account = $this->account();

        $this->meter()->charge($account, 'create_form');

        $this->assertSame(3, $this->meter()->usedSince($account, now()->startOfMonth()));
    }

    public function test_an_unpriced_action_is_free_rather_than_a_guess(): void
    {
        $account = $this->account();

        $this->meter()->charge($account, 'something_nobody_priced');

        $this->assertSame(0, $this->meter()->usedSince($account, now()->startOfMonth()));
    }

    public function test_metered_units_are_priced_by_operation(): void
    {
        config(['larameter.rates.token' => [
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'per' => 1_000_000],
        ]]);

        $account = $this->account();
        $meter = $this->meter();

        // 1M in + 1M out at those rates = $12.50, at 10000 credits per unit of cost.
        $record = $meter->meter($account, 'gpt-4o', 'token', 1_000_000, 1_000_000);

        $this->assertSame('12.500000', $record->cost);
        $this->assertSame(125000, $record->credits);
    }

    public function test_an_unknown_model_still_costs_something(): void
    {
        // Otherwise metering something unpriced is free, and the gap only shows up on
        // the provider's invoice.
        $account = $this->account();

        $record = $this->meter()->meter($account, 'a-model-nobody-configured', 'token', 5_000, 5_000);

        $this->assertGreaterThan(0, $record->credits);
    }

    public function test_the_weekly_brake_bites_before_the_monthly_budget(): void
    {
        // Without it an account burns a month in a bad afternoon and spends the rest of
        // it locked out, which reads as the product being broken.
        $account = $this->account();
        $meter = $this->meter(['credits_monthly' => 100]);

        $this->assertSame(25, $meter->weeklyBudget($account));

        $meter->charge($account, 'x', credits: 25);

        $this->assertSame(0, $meter->remaining($account));
        $this->assertFalse($meter->hasCredits($account));
        $this->assertSame('weekly', $meter->limitHit($account));
    }

    public function test_remaining_is_whichever_window_is_tighter(): void
    {
        $account = $this->account();
        $meter = $this->meter(['credits_monthly' => 100]);

        $meter->charge($account, 'x', credits: 10);

        // 90 left this month, 15 left this week: the week wins.
        $this->assertSame(15, $meter->remaining($account));
    }

    public function test_the_memo_answers_once_and_does_not_notice_later_spending(): void
    {
        // Deliberate: a turn that starts with credit finishes. Stopping halfway leaves a
        // half-built answer, and the overshoot is bounded to one turn.
        $account = $this->account();
        $meter = $this->meter(['credits_monthly' => 40]);

        $this->assertTrue($meter->hasCreditsMemoized($account));

        $meter->charge($account, 'x', credits: 40);

        $this->assertTrue($meter->hasCreditsMemoized($account), 'still yes within this instance');
        $this->assertFalse($meter->hasCredits($account), 'but the truth has moved on');
    }

    public function test_quantity_caps_are_a_different_question_from_credits(): void
    {
        // Seats and projects are ceilings, not spend.
        $account = $this->account();
        $meter = $this->meter(['seats' => 3]);

        $this->assertTrue($meter->canCreate($account, 'seats', 2));
        $this->assertFalse($meter->canCreate($account, 'seats', 3));
        $this->assertTrue($meter->canCreate($account, 'projects', 9999), 'unlimited by default');
    }

    public function test_spend_can_be_traced_back_to_what_caused_it(): void
    {
        $account = $this->account();
        $subject = Account::create();

        $record = $this->meter()->charge($account, 'generate_document', subject: $subject);

        $this->assertTrue($record->subject->is($subject));
        $this->assertTrue($record->account->is($account));
    }
}
