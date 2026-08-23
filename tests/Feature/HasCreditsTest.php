<?php

namespace EduLazaro\Laracredits\Tests\Feature;

use EduLazaro\Laracredits\Tests\Fixtures\Account;
use EduLazaro\Laracredits\Tests\TestCase;

/**
 * The three-step setup: publish the config, name your plans, add the trait.
 */
class HasCreditsTest extends TestCase
{
    public function test_the_trait_is_the_whole_api_most_apps_need(): void
    {
        config([
            'laracredits.plans.free.credits_monthly' => 400,
            'laracredits.prices.create_form' => 5,
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
        config(['laracredits.default_plan' => 'free']);

        $account = Account::create();
        $this->assertSame('free', $account->creditPlanKey());

        $account->plan = 'pro';
        $this->assertSame('pro', $account->creditPlanKey());
    }
}
