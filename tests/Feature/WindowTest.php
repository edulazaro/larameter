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
            'session' => ['minutes' => 300, 'anchor' => 'rolling'],
            'week' => ['days' => 7, 'anchor' => 'fixed'],
        ]);
        config()->set('larameter.plans', [
            'free' => ['credits' => ['session' => 50, 'week' => 200]],
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

    public function test_asking_the_balance_never_opens_a_window(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();

        // The row IS the clock. If merely looking created one, opening the app would burn
        // the session without a word having been typed.
        $this->assertSame(50, $org->creditsRemaining());
        $this->assertSame(0, Window::count());
        $this->assertFalse($org->hasCredits(999));
        $this->assertSame(0, Window::count());
    }

    public function test_asking_after_a_window_expires_reports_it_full_without_restarting_it(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->chargeCredits('thing', credits: 50);

        $this->assertSame(0, $org->creditsRemaining());
        $this->assertSame('2026-01-10 09:00:00', $this->window($org, 'session')->started_at->toDateTimeString());

        Carbon::setTestNow('2026-01-10 20:00:00');

        // Reported full, but the row has not moved: the five hours do not start until
        // they actually spend something.
        $this->assertSame(50, $org->creditsRemaining());
        $this->assertSame('2026-01-10 09:00:00', $this->window($org, 'session')->started_at->toDateTimeString());
    }

    public function test_a_rolling_window_gives_the_full_length_from_when_they_came_back(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();
        $org->chargeCredits('thing', credits: 50);

        Carbon::setTestNow('2026-01-10 20:00:00');

        $org->chargeCredits('thing', credits: 10);

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
        $org->chargeCredits('thing', credits: 10);

        // Grid: Jan 1-8, Jan 8-15, Jan 15-22.
        Carbon::setTestNow('2026-01-20 09:00:00');

        $org->chargeCredits('thing', credits: 10);

        $week = $this->window($org, 'week');

        $this->assertSame('2026-01-15 09:00:00', $week->started_at->toDateTimeString());
        $this->assertSame('2026-01-22 09:00:00', $week->endsAt()->toDateTimeString(), 'two days left, not seven');
        $this->assertSame(10, $week->credits_used, 'the old week did not carry over');
    }

    public function test_a_dormant_account_gets_one_allowance_back_and_not_four(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');

        $org = $this->org();
        $org->chargeCredits('thing', credits: 200);

        $this->assertSame(0, $org->creditHeadroom());

        Carbon::setTestNow('2026-02-01 09:00:00');

        $org->chargeCredits('thing', credits: 1);

        // Four weeks passed. One allowance, minus the credit just spent.
        $this->assertSame(49, $org->creditHeadroom(), 'the session is what binds now');
        $this->assertSame(1, $this->window($org, 'week')->credits_used);
    }

    public function test_a_window_with_no_length_is_a_config_error_that_says_which_one(): void
    {
        config()->set('larameter.windows', ['broken' => ['anchor' => 'fixed']]);
        config()->set('larameter.plans', ['free' => ['credits' => ['broken' => 10]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('window [broken] has no length');

        $this->org()->creditsRemaining();
    }

    public function test_declaring_no_windows_at_all_opts_out_of_allowance_metering(): void
    {
        config()->set('larameter.windows', []);

        $org = $this->org();
        $org->chargeCredits('thing', credits: 10_000);

        // Usage is still recorded. Nothing is refused, because there is no period for an
        // allowance to be an allowance of.
        $this->assertSame(PHP_INT_MAX, $org->creditsRemaining());
        $this->assertSame(1, $org->creditUsage()->count());
        $this->assertSame(0, Window::count());
    }

    public function test_when_they_can_spend_again(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');

        $org = $this->org();

        $this->assertNull($org->creditsResetAt(), 'nothing is blocking them');

        $org->chargeCredits('thing', credits: 50);

        $this->assertSame('2026-01-10 14:00:00', Carbon::instance($org->creditsResetAt())->toDateTimeString());

        // Buying credits unblocks them now, so there is nothing to wait for.
        $org->depositCredits(100);

        $this->assertNull($org->creditsResetAt());
    }
}
