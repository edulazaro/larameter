<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The trait is the whole install. If anything here needs an interface, a binding or a
 * method the host app has to write, the package has failed at the thing it is for.
 */
class HasCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', ['week' => ['days' => 7, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', [
            'free' => ['credits' => ['week' => 10]],
            'pro' => ['credits' => ['week' => 500]],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.prices', ['create_form' => 3]);
    }

    public function test_the_trait_is_the_whole_api_most_apps_need(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $this->assertTrue($org->hasCredits());
        $this->assertSame(10, $org->creditAllowanceIn('week'));
        $this->assertSame(10, $org->creditsRemaining());

        $org->chargeCredits('create_form');

        $this->assertSame(7, $org->creditsRemaining());
        $this->assertSame(1, $org->creditUsage()->count());

        $org->chargeCredits('create_form', credits: 7);

        $this->assertSame(0, $org->creditsRemaining());
        $this->assertFalse($org->hasCredits());
    }

    public function test_the_plan_is_set_from_wherever_the_app_learns_it_changed(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $this->assertSame('free', $org->creditPlan());

        // From a Cashier subscription observer, a grant, an admin action. The package
        // cannot know, so it does not try.
        $org->setCreditPlan('pro');

        $this->assertSame('pro', $org->creditPlan());
        $this->assertSame(500, $org->creditHeadroom());

        $org->setCreditPlan(null);

        $this->assertSame(0, $org->creditHeadroom());
    }

    public function test_buying_credits_adds_to_a_bucket_no_window_can_touch(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $org->depositCredits(1_000);

        $this->assertSame(1_010, $org->creditsRemaining());
    }
}
