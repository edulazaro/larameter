<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\CashierPlan;
use EduLazaro\Larameter\PlanProviders\CashierPlanProvider;
use EduLazaro\Larameter\PlanProviders\ForcedPlanProvider;
use EduLazaro\Larameter\PlanProviders\StoredPlanProvider;
use EduLazaro\Larameter\Tests\Fixtures\FakeCashierPlanProvider;
use EduLazaro\Larameter\Tests\Fixtures\Subscriber;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Where a plan comes from, and in what order.
 *
 * The order is the policy, not an implementation detail: an override before a
 * subscription means a decision somebody made by hand beats what Stripe thinks.
 */
class PlanProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('organizations', function ($table) {
            $table->string('forced_plan')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Subscriber::flushPlanProviders();

        config()->set('larameter.plans', [
            'free' => ['credits' => ['month' => 100]],
            'pro' => ['stripe_price_id' => 'price_pro', 'credits' => ['month' => 5_000]],
            'max' => ['stripe_price_id' => 'price_max', 'credits' => ['month' => 50_000]],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.override_column', 'forced_plan');
        config()->set('larameter.plan_providers', [
            ForcedPlanProvider::class,
            FakeCashierPlanProvider::class,
            StoredPlanProvider::class,
        ]);
    }

    private function subscriber(?string $price = null, ?string $forced = null): Subscriber
    {
        $model = Subscriber::create(['name' => 'Acme', 'forced_plan' => $forced]);
        $model->subscribedPrice = $price;

        return $model;
    }

    public function test_the_subscription_decides_when_nothing_overrides_it(): void
    {
        $this->assertSame('pro', $this->subscriber(price: 'price_pro')->plan()->handle);
        $this->assertSame('max', $this->subscriber(price: 'price_max')->plan()->handle);
    }

    public function test_a_plan_from_a_subscription_knows_it_came_from_one(): void
    {
        // Which is what lets the billing windows line up with the invoice instead of with
        // whenever somebody first used the thing.
        $this->assertInstanceOf(CashierPlan::class, $this->subscriber(price: 'price_pro')->plan());
    }

    public function test_an_override_beats_the_subscription(): void
    {
        $model = $this->subscriber(price: 'price_pro', forced: 'max');

        // Somebody set this by hand, for a partner or a demo. Stripe does not undo it.
        $this->assertSame('max', $model->plan()->handle);
    }

    public function test_a_price_belonging_to_no_plan_falls_through_instead_of_inventing_one(): void
    {
        $model = $this->subscriber(price: 'price_that_nobody_mapped');

        // A price you forgot to map shows up as the default, not as unlimited everything.
        $this->assertSame('free', $model->plan()->handle);
    }

    public function test_no_subscription_falls_through_to_what_was_stored(): void
    {
        $model = $this->subscriber();
        $model->setCreditPlan('max');

        $this->assertSame('max', $model->plan()->handle);
    }

    public function test_the_real_provider_is_inert_without_cashier(): void
    {
        // The shipped one guards on Cashier being installed, and it is not, here.
        $this->assertNull((new CashierPlanProvider())->provide($this->subscriber(price: 'price_pro')));
    }

    public function test_the_credits_come_from_the_resolved_plan_and_not_the_stored_column(): void
    {
        $model = $this->subscriber(price: 'price_max');
        $model->setCreditPlan('free');

        // The bug this exists to catch: plan() answered 'max' while the allowance came
        // from whatever the stored column said, and nothing anywhere disagreed out loud.
        $this->assertSame('max', $model->plan()->handle);
        $this->assertSame(50_000, $model->creditHeadroom());
    }

    public function test_a_model_can_be_billed_differently_from_the_rest(): void
    {
        Subscriber::setPlanProviders([StoredPlanProvider::class]);

        $model = $this->subscriber(price: 'price_max', forced: 'pro');

        // Neither the override nor the subscription is consulted: this model was told to
        // use one provider and it uses one.
        $this->assertSame('free', $model->plan()->handle);
    }
}
