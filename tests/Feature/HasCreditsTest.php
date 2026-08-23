<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Account;
use EduLazaro\Larameter\Tests\TestCase;

/**
 * The three-step setup: publish the config, name your plans, add the trait.
 */
class HasCreditsTest extends TestCase
{
    public function test_the_trait_is_the_whole_api_most_apps_need(): void
    {
        config([
            'larameter.plans.free.credits_monthly' => 400,
            'larameter.prices.create_form' => 5,
        ]);

        $account = Account::create();

        $this->assertSame(400, $account->creditBudget());
        $this->assertTrue($account->hasCredits());

        $account->chargeCredits('create_form');

        $this->assertSame(95, $account->creditsRemaining(), 'the weekly brake, 100 minus 5');
        $this->assertCount(1, $account->usageRecords);
    }

    public function test_the_plan_comes_from_the_model_and_falls_back(): void
    {
        config(['larameter.default_plan' => 'free']);

        $account = Account::create();
        $this->assertSame('free', $account->creditPlanKey());

        $account->plan = 'pro';
        $this->assertSame('pro', $account->creditPlanKey());
    }
}
