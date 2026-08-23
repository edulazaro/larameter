<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\CashierPlan;
use EduLazaro\Larameter\Models\Window;
use EduLazaro\Larameter\PlanProviders\StoredPlanProvider;
use EduLazaro\Larameter\Tests\Fixtures\FakeCashierPlanProvider;
use EduLazaro\Larameter\Tests\Fixtures\FakeSubscription;
use EduLazaro\Larameter\Tests\Fixtures\Subscriber;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * A plan that came from a subscription, and what that is for.
 *
 * These exist because an audit found every method on CashierPlan untested, including the
 * one the class was built for. Worse, nothing called renewsAt() or startedAt() at all: the
 * promise that windows would line up with the invoice on their own was not kept anywhere.
 */
class CashierPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Subscriber::flushPlanProviders();
        Subscriber::$subscriptions = [];

        config()->set('larameter.windows', ['month' => ['months' => 1, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', [
            'free' => ['credits' => ['month' => 100]],
            'pro' => ['stripe_price_id' => 'price_pro', 'credits' => ['month' => 5_000]],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.override_column', null);
        config()->set('larameter.plan_providers', [FakeCashierPlanProvider::class, StoredPlanProvider::class]);
    }

    private function subscriber(?FakeSubscription $subscription): Subscriber
    {
        $model = Subscriber::create(['name' => 'Acme']);

        Subscriber::subscribeFake($model->id, 'price_pro', $subscription);

        return $model;
    }

    public function test_it_reports_the_billing_dates(): void
    {
        $plan = new CashierPlan('pro', [], new FakeSubscription(
            periodStart: Carbon::parse('2026-03-15 10:00:00')->timestamp,
            periodEnd: Carbon::parse('2026-04-15 10:00:00')->timestamp,
        ));

        $this->assertSame('2026-03-15 10:00:00', $plan->startedAt()->toDateTimeString());
        $this->assertSame('2026-04-15 10:00:00', $plan->renewsAt()->toDateTimeString());
    }

    public function test_it_reports_a_trial_and_a_cancellation(): void
    {
        $trialing = new CashierPlan('pro', [], new FakeSubscription(trialing: true));
        $cancelled = new CashierPlan('pro', [], new FakeSubscription(canceledFlag: true));

        $this->assertTrue($trialing->onTrial());
        $this->assertTrue($cancelled->cancelled());
        $this->assertFalse($trialing->cancelled());
    }

    public function test_a_plan_with_no_subscription_answers_without_blowing_up(): void
    {
        $plan = new CashierPlan('pro', []);

        $this->assertNull($plan->renewsAt());
        $this->assertNull($plan->startedAt());
        $this->assertFalse($plan->onTrial());
    }

    public function test_stripe_being_down_gives_no_date_rather_than_an_exception(): void
    {
        $plan = new CashierPlan('pro', [], new FakeSubscription(explode: true));

        // A plan has to stay readable when the network is not. Throwing here would take
        // out any page that shows a balance.
        $this->assertNull($plan->renewsAt());
    }

    public function test_the_window_grid_is_laid_on_the_billing_date(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');

        // Tried it on the 20th, but has been paying since the 15th.
        $org = $this->subscriber(new FakeSubscription(
            periodStart: Carbon::parse('2026-03-15 10:00:00')->timestamp,
        ));

        $org->chargeCredits('first use', credits: 10);

        $window = Window::where('key', 'month')->sole();

        // The grid is the invoice's. Anchored to first use instead, this customer would
        // get a fresh allowance on the 20th of every month, five days after paying for
        // one, and it always falls their way so nobody ever reports it.
        $this->assertSame('2026-03-15 10:00:00', $window->started_at->toDateTimeString());
    }

    public function test_without_a_subscription_the_grid_starts_now(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');

        $org = Subscriber::create(['name' => 'Acme']);
        $org->chargeCredits('first use', credits: 10);

        $this->assertSame('2026-03-20 09:00:00', Window::where('key', 'month')->sole()->started_at->toDateTimeString());
    }
}
