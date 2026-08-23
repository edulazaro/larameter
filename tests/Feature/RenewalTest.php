<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Window;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Lining the weekly and monthly caps up with what Stripe actually charges.
 *
 * Stripe bills by anniversary unless you tell it otherwise, so the grid has to be the
 * customer's, not the calendar's. Anchoring credits to the 1st while charging on the 28th
 * hands every new account an extra allowance, and it always falls the customer's way, so
 * nobody ever reports it.
 */
class RenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', [
            'session' => ['minutes' => 300, 'anchor' => 'rolling'],
            'week' => ['days' => 7, 'anchor' => 'fixed'],
            'month' => ['months' => 1, 'anchor' => 'fixed'],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits' => ['session' => 50, 'week' => 200, 'month' => 800]],
            'pro' => ['credits' => ['session' => 500, 'week' => 2_000, 'month' => 8_000]],
        ]);
        config()->set('larameter.default_plan', 'free');
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme']);
    }

    private function window(Organization $org, string $key): ?Window
    {
        return Window::where('account_id', $org->creditAccount()->getKey())->where('key', $key)->first();
    }

    public function test_paying_lines_the_windows_up_with_the_billing_date(): void
    {
        // Tried it on Tuesday.
        Carbon::setTestNow('2026-01-13 10:00:00');
        $org = $this->org();
        $org->chargeCredits('a look around', credits: 10);

        // Paid on Thursday. Without this call the grid stays on Tuesday and they get a
        // fresh allowance five days after paying, every single month.
        Carbon::setTestNow('2026-01-15 18:30:00');
        $org->startCreditPeriod();

        $this->assertSame('2026-01-15 18:30:00', $this->window($org, 'week')->started_at->toDateTimeString());
        $this->assertSame('2026-01-15 18:30:00', $this->window($org, 'month')->started_at->toDateTimeString());
        $this->assertSame(0, $this->window($org, 'week')->credits_used, 'the trial usage does not eat the paid week');
    }

    public function test_it_takes_the_instant_stripe_gives_you(): void
    {
        Carbon::setTestNow('2026-02-15 09:00:00');

        $org = $this->org();
        $org->startCreditPeriod(Carbon::parse('2026-02-15 08:59:31'));

        $this->assertSame('2026-02-15 08:59:31', $this->window($org, 'month')->started_at->toDateTimeString());
    }

    public function test_a_renewal_starts_the_month_again(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');
        $org = $this->org();
        $org->startCreditPeriod();
        $org->chargeCredits('work', credits: 50);

        $this->assertSame(50, $this->window($org, 'month')->credits_used);

        Carbon::setTestNow('2026-02-15 12:00:00');
        $org->startCreditPeriod();

        $this->assertSame(0, $this->window($org, 'month')->credits_used);
        $this->assertSame('2026-02-15 12:00:00', $this->window($org, 'month')->started_at->toDateTimeString());
    }

    public function test_the_same_instant_twice_cannot_be_replayed_for_a_second_allowance(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');
        $org = $this->org();
        $renewedAt = Carbon::parse('2026-01-15 12:00:00');

        $org->startCreditPeriod($renewedAt);

        // 50 and not more: the session is the tightest window, so it is all the plan
        // will pay for in one sitting however much you charge.
        $org->chargeCredits('work', credits: 50);

        // A webhook delivered twice, a retry, somebody hammering a button.
        $org->startCreditPeriod($renewedAt);

        $this->assertSame(50, $this->window($org, 'month')->credits_used, 'nothing was handed back');
    }

    public function test_a_renewal_does_not_hand_back_the_session_they_just_spent(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');
        $org = $this->org();
        $org->chargeCredits('work', credits: 50);

        $this->assertSame(0, $org->creditHeadroom(), 'the session is spent');

        $org->startCreditPeriod();

        // A session is not a billing period. Renewing at 12:00 must not give back the
        // five hours they burned at 11:00.
        $this->assertSame(0, $org->creditHeadroom());
        $this->assertSame(50, $this->window($org, 'session')->credits_used);
    }

    public function test_changing_plan_never_realigns_the_windows(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');
        $org = $this->org();
        $org->startCreditPeriod();
        $org->chargeCredits('work', credits: 50);

        Carbon::setTestNow('2026-01-20 12:00:00');

        $org->setCreditPlan('pro');
        $org->setCreditPlan('free');
        $org->setCreditPlan('pro');

        // Upgrade, new allowance, downgrade, repeat: the door stays shut. The window is
        // still the one that opened on the 15th, with the 50 still spent in it.
        $this->assertSame('2026-01-15 12:00:00', $this->window($org, 'month')->started_at->toDateTimeString());
        $this->assertSame(50, $this->window($org, 'month')->credits_used);
    }

    public function test_a_free_account_that_never_pays_still_gets_its_grid(): void
    {
        Carbon::setTestNow('2026-01-13 10:00:00');

        $org = $this->org();
        $org->chargeCredits('work', credits: 10);

        // Nobody called startCreditPeriod, because nobody paid. First use anchors it,
        // which is the right answer when there is no invoice to line up with.
        $this->assertSame('2026-01-13 10:00:00', $this->window($org, 'month')->started_at->toDateTimeString());
    }
}
