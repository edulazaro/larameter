<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Window;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** When an allowance comes back, and what that costs the person asking. */
class WindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', [
            'session' => ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.25],
            'week' => ['days' => 7, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits_monthly' => 200],
        ]);
        config()->set('larameter.default_plan', 'free');
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme']);
    }

    private function window(Organization $org, string $key): ?Window
    {
        return Window::where('account_id', $org->credits()->account()->getKey())->where('key', $key)->first();
    }

    public function test_asking_the_balance_never_opens_a_window(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();

        // The row IS the clock. If merely looking created one, opening the app would burn
        // the session without a word having been typed.
        $this->assertSame(50, $org->credits()->remaining());
        $this->assertSame(0, Window::count());
        $this->assertFalse($org->credits()->allows(999));
        $this->assertSame(0, Window::count());
    }

    public function test_asking_after_a_window_expires_reports_it_full_without_restarting_it(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 50);

        $this->assertSame(0, $org->credits()->remaining());
        $this->assertSame('2026-01-10 09:00:00', $this->window($org, 'session')->started_at->toDateTimeString());

        Carbon::setTestNow('2026-01-10 20:00:00');

        // Reported full, but the row has not moved: the five hours do not start until
        // they actually spend something.
        $this->assertSame(50, $org->credits()->remaining());
        $this->assertSame('2026-01-10 09:00:00', $this->window($org, 'session')->started_at->toDateTimeString());
    }

    public function test_a_rolling_window_gives_the_full_length_from_when_they_came_back(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 50);

        Carbon::setTestNow('2026-01-10 20:00:00');

        $org->credits()->charge('thing', credits: 10);

        $session = $this->window($org, 'session');

        // 20:00 to 01:00, the whole five hours. On a fixed grid they would have landed
        // in the 19:00 slot with an hour left, which reads as the product robbing them.
        $this->assertSame('2026-01-10 20:00:00', $session->started_at->toDateTimeString());
        $this->assertSame('2026-01-11 01:00:00', $session->endsAt()->toDateTimeString());
        $this->assertSame(10, $session->credits_used);
    }

    public function test_a_fixed_window_keeps_its_grid_so_coming_back_late_lands_mid_window(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 10);

        // Grid: Jan 1-8, Jan 8-15, Jan 15-22.
        Carbon::setTestNow('2026-01-20 09:00:00');

        $org->credits()->charge('thing', credits: 10);

        $week = $this->window($org, 'week');

        $this->assertSame('2026-01-15 09:00:00', $week->started_at->toDateTimeString());
        $this->assertSame('2026-01-22 09:00:00', $week->endsAt()->toDateTimeString(), 'two days left, not seven');
        $this->assertSame(10, $week->credits_used, 'the old week did not carry over');
    }

    public function test_a_dormant_account_gets_one_allowance_back_and_not_four(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 200);

        $this->assertSame(0, $org->credits()->headroom());

        Carbon::setTestNow('2026-02-01 09:00:00');

        $org->credits()->charge('thing', credits: 1);

        // Four weeks passed. One allowance, minus the credit just spent.
        $this->assertSame(49, $org->credits()->headroom(), 'the session is what binds now');
        $this->assertSame(1, $this->window($org, 'week')->credits_used);
    }

    public function test_the_weekly_and_monthly_caps_are_independent_of_each_other(): void
    {
        // 2000 a week inside 4000 a month. The weekly is not a share of the monthly, it
        // is its own ceiling, and whichever runs out first is the one that binds.
        config()->set('larameter.windows', [
            'week' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.5],
            'month' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 4_000]]);

        Carbon::setTestNow('2026-03-02 09:00:00');
        $org = $this->org();

        $this->assertSame(2_000, $org->credits()->headroom(), 'the week is what binds at the start');

        $org->credits()->charge('a heavy week', credits: 2_000);
        $this->assertSame(0, $org->credits()->headroom());

        // Second week: the weekly came back, the monthly did not.
        Carbon::setTestNow('2026-03-09 09:00:00');
        $this->assertSame(2_000, $org->credits()->headroom());

        $org->credits()->charge('another heavy week', credits: 2_000);

        // Third week. The week is fresh and the month is spent, so the month binds now.
        Carbon::setTestNow('2026-03-16 09:00:00');
        $this->assertSame(0, $org->credits()->headroom(), 'the month is gone until it renews');
    }

    public function test_an_unused_week_does_not_pile_up_into_the_next_one(): void
    {
        config()->set('larameter.windows', [
            'week' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.5],
            'month' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 4_000]]);

        Carbon::setTestNow('2026-03-02 09:00:00');
        $org = $this->org();
        $org->credits()->charge('open the grid', credits: 1);

        // Three weeks untouched.
        Carbon::setTestNow('2026-03-23 09:00:00');

        // Still 2000, not 6000. This is the whole point of a weekly cap: it is a brake,
        // and a brake you can save up is not a brake.
        $this->assertSame(2_000, $org->credits()->headroom());
    }

    public function test_every_window_takes_its_share_of_one_figure(): void
    {
        config()->set('larameter.windows', [
            'session' => ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.04],
            'weekly' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.25],
            'monthly' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 50_000]]);

        $org = $this->org();

        $this->assertSame(2_000, $org->credits()->allowanceIn('session'));
        $this->assertSame(12_500, $org->credits()->allowanceIn('weekly'));
        $this->assertSame(50_000, $org->credits()->allowanceIn('monthly'));
    }

    public function test_raising_a_plan_raises_every_window_with_it(): void
    {
        config()->set('larameter.windows', [
            'weekly' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.25],
            'monthly' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits_monthly' => 1_000],
            'pro' => ['credits_monthly' => 100_000],
        ]);

        $org = $this->org();
        $org->credits()->setPlan('pro');

        // One figure per plan is the point: nobody can raise the monthly and leave the
        // weekly behind, where it would silently become the binding constraint.
        $this->assertSame(25_000, $org->credits()->allowanceIn('weekly'));
    }

    public function test_a_window_with_no_length_is_a_config_error_that_says_which_one(): void
    {
        config()->set('larameter.windows', ['broken' => ['anchor' => 'fixed']]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 10]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('window [broken] has no length');

        $this->org()->credits()->remaining();
    }

    public function test_declaring_no_windows_at_all_opts_out_of_allowance_metering(): void
    {
        config()->set('larameter.windows', []);

        $org = $this->org();
        $org->credits()->charge('thing', credits: 10_000);

        // Usage is still recorded. Nothing is refused, because there is no period for an
        // allowance to be an allowance of.
        $this->assertSame(PHP_INT_MAX, $org->credits()->remaining());
        $this->assertSame(1, $org->credits()->records()->count());
        $this->assertSame(0, Window::count());
    }

    public function test_when_they_can_spend_again(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();

        $this->assertNull($org->credits()->resetsAt(), 'nothing is blocking them');

        $org->credits()->charge('thing', credits: 50);

        $this->assertSame('2026-01-10 14:00:00', Carbon::instance($org->credits()->resetsAt())->toDateTimeString());

        // Buying credits unblocks them now, so there is nothing to wait for.
        $org->credits()->deposit(100);

        $this->assertNull($org->credits()->resetsAt());
    }
}
