<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Which plan an account is on, and where that answer comes from.
 *
 * The app this came from computed it on every call and never stored it, which meant
 * larameter had to be told. Working it out here removes the line where the host app
 * plumbs two things together, and that line is where a plan change gets forgotten.
 */
class PlanResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('organizations', function ($table) {
            $table->string('forced_plan')->nullable();
        });
    }

    private function plans(): void
    {
        config()->set('larameter.plans', [
            'free' => ['name' => 'Free', 'credits' => ['month' => 100]],
            'pro' => ['name' => 'Pro', 'credits' => ['month' => 5_000], 'features' => ['api_access' => true]],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.override_column', 'forced_plan');
    }

    public function test_the_default_when_nothing_says_otherwise(): void
    {
        $this->plans();

        $this->assertSame('free', Organization::create(['name' => 'Acme'])->plan()->handle);
    }

    public function test_the_override_column_wins(): void
    {
        $this->plans();

        $org = Organization::create(['name' => 'Acme', 'forced_plan' => 'pro']);

        // A person decided this by hand, for a demo or a partner, and it beats money.
        $this->assertSame('pro', $org->plan()->handle);
        $this->assertTrue($org->plan()->allows('api_access'));
    }

    public function test_an_override_naming_a_plan_that_no_longer_exists_is_ignored(): void
    {
        $this->plans();

        $org = Organization::create(['name' => 'Acme', 'forced_plan' => 'legacy_gold']);

        // Rather than leaving the account on a plan whose credits and limits cannot be
        // read, which reads as unlimited everything.
        $this->assertSame('free', $org->plan()->handle);
    }

    public function test_what_was_stored_is_used_when_nothing_resolves_it(): void
    {
        $this->plans();
        config()->set('larameter.override_column', null);

        $org = Organization::create(['name' => 'Acme']);
        $org->setCreditPlan('pro');

        // The path for an app with no subscriptions at all: bundles of credits and a
        // plan somebody set.
        $this->assertSame('pro', $org->plan()->handle);
    }

    public function test_setting_a_plan_forgets_the_one_already_resolved(): void
    {
        $this->plans();
        config()->set('larameter.override_column', null);

        $org = Organization::create(['name' => 'Acme']);
        $this->assertSame('free', $org->plan()->handle);

        $org->setCreditPlan('pro');

        // Without forgetting, the memo would keep answering 'free' for the rest of the
        // request, and whatever was charged next would be charged against the old plan.
        $this->assertSame('pro', $org->plan()->handle);
    }

    public function test_no_plan_at_all_is_a_plan_that_grants_nothing(): void
    {
        config()->set('larameter.plans', []);
        config()->set('larameter.default_plan', null);
        config()->set('larameter.override_column', null);

        $org = Organization::create(['name' => 'Acme']);

        // Never null, so nothing downstream needs a null check.
        $this->assertFalse($org->plan()->exists);
        $this->assertFalse($org->plan()->allows('api_access'));
        $this->assertSame(0, $org->creditHeadroom());
    }

    public function test_no_plan_answers_everything_without_anybody_checking_for_null(): void
    {
        config()->set('larameter.plans', []);
        config()->set('larameter.default_plan', null);
        config()->set('larameter.override_column', null);

        $plan = Organization::create(['name' => 'Acme'])->plan();

        $this->assertFalse($plan->exists);
        $this->assertSame('', $plan->name);
        $this->assertFalse($plan->allows('api_access'));
        $this->assertSame(0, $plan->credits('month'));
        $this->assertSame(-1, $plan->limit('members'), 'a ceiling nobody wrote down never applied');
    }

    public function test_exists_tells_no_plan_apart_from_a_plan_that_grants_little(): void
    {
        config()->set('larameter.plans', ['tiny' => ['credits' => ['month' => 0]]]);
        config()->set('larameter.default_plan', 'tiny');
        config()->set('larameter.override_column', null);

        // Both grant nothing. Only one of them is a plan.
        $this->assertTrue(Organization::create(['name' => 'Acme'])->plan()->exists);
    }

    public function test_plans_can_live_in_the_apps_own_config_file(): void
    {
        config()->set('plans', [
            'credit_value' => 0.0001,
            'tiers' => ['pro' => ['name' => 'Pro', 'credits' => ['month' => 5_000]]],
        ]);
        config()->set('larameter.plans_from', 'plans.tiers');
        config()->set('larameter.default_plan', 'pro');
        config()->set('larameter.override_column', null);

        $org = Organization::create(['name' => 'Acme']);

        $this->assertSame('Pro', $org->plan()->name);
        $this->assertSame(5_000, $org->plan()->credits('month'));
    }

    public function test_without_cashier_the_subscription_step_is_simply_skipped(): void
    {
        $this->plans();

        // Nothing here implements subscribed(), and the resolver checks before asking
        // rather than assuming Cashier is installed.
        $this->assertSame('free', Organization::create(['name' => 'Acme'])->plan()->handle);
    }
}
