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
            'free' => ['credits_monthly' => 10],
            'pro' => ['credits_monthly' => 500],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.prices', ['create_form' => 3]);
    }

    public function test_the_trait_is_the_whole_api_most_apps_need(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $this->assertTrue($org->credits()->allows());
        $this->assertSame(10, $org->credits()->allowanceIn('week'));
        $this->assertSame(10, $org->credits()->remaining());

        $org->credits()->charge('create_form');

        $this->assertSame(7, $org->credits()->remaining());
        $this->assertSame(1, $org->credits()->records()->count());

        $org->credits()->charge('create_form', credits: 7);

        $this->assertSame(0, $org->credits()->remaining());
        $this->assertFalse($org->credits()->allows());
    }

    public function test_the_plan_is_set_from_wherever_the_app_learns_it_changed(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $this->assertSame('free', $org->plan()->handle);

        // From a Cashier subscription observer, a grant, an admin action. The package
        // cannot know, so it does not try.
        $org->credits()->setPlan('pro');

        $this->assertSame('pro', $org->plan()->handle);
        $this->assertSame(500, $org->credits()->headroom());

        // Clearing the stored key does not mean "no plan": it means stop forcing one, so
        // whatever resolves next decides. With no subscription that is the default.
        $org->credits()->setPlan(null);

        $this->assertTrue($org->plan()->is('free'));
    }

    public function test_buying_credits_adds_to_a_bucket_no_window_can_touch(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $org->credits()->deposit(1_000);

        $this->assertSame(1_010, $org->credits()->remaining());
    }

    public function test_it_answers_what_an_action_costs_without_being_told(): void
    {
        config()->set('larameter.prices', ['search_legislation' => 10]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 25]]);
        config()->set('larameter.default_plan', 'free');

        $org = Organization::create(['name' => 'Acme']);

        $this->assertSame(10, $org->credits()->price('search_legislation'));
        $this->assertTrue($org->credits()->allows('search_legislation'));

        $org->credits()->charge('search_legislation');
        $org->credits()->charge('search_legislation');

        // Five left, and the search costs ten.
        $this->assertSame(5, $org->fresh()->credits()->remaining());
        $this->assertFalse($org->fresh()->credits()->allows('search_legislation'));
        $this->assertTrue($org->fresh()->credits()->allows(), 'still has credit, just not enough');
    }

    public function test_an_unpriced_action_is_always_affordable(): void
    {
        config()->set('larameter.prices', []);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 0]]);
        config()->set('larameter.default_plan', 'free');

        $org = Organization::create(['name' => 'Acme']);

        $this->assertFalse($org->credits()->allows());
        $this->assertTrue($org->credits()->allows('something_free'));
    }
}
