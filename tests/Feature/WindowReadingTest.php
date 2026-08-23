<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reading a window, which is what a usage screen is made of: a bar per window with what
 * the plan grants, what has gone, and when it starts over.
 *
 * The screen was the reason this exists. Without it the only way to draw those bars was
 * to reach past the API into the rows, from a template.
 */
class WindowReadingTest extends TestCase
{
    use RefreshDatabase;

    private function org(bool $withSession = true): Organization
    {
        config()->set('larameter.windows', array_filter([
            'session' => $withSession ? ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.04] : null,
            'weekly' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.25],
            'monthly' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
        ]));
        config()->set('larameter.plans', ['pro' => ['credits_monthly' => 50_000]]);
        config()->set('larameter.default_plan', 'pro');

        return Organization::create(['name' => 'Acme']);
    }

    public function test_one_window_answers_what_a_bar_needs(): void
    {
        // No session window here: at 4% it caps a single charge at 2.000, and this is
        // about reading the weekly bar rather than about which window binds.
        $org = $this->org(withSession: false);
        $org->credits()->charge('thing', credits: 12_000);

        $weekly = $org->fresh()->credits()->in('weekly');

        $this->assertSame(12_500, $weekly->allowance());
        $this->assertSame(12_000, $weekly->used());
        $this->assertSame(500, $weekly->remaining());
        $this->assertSame(96.0, $weekly->percentUsed());
        $this->assertTrue($weekly->endsAt()->isFuture());
    }

    public function test_every_window_comes_back_for_the_screen(): void
    {
        $org = $this->org();
        $org->credits()->charge('thing', credits: 1_000);

        $windows = $org->fresh()->credits()->windows();

        $this->assertSame(['session', 'weekly', 'monthly'], array_keys($windows));
        $this->assertSame(2_000, $windows['session']->allowance());
        $this->assertSame(1_000, $windows['session']->used());
        $this->assertSame(50_000, $windows['monthly']->allowance());
    }

    public function test_looking_does_not_start_the_clock(): void
    {
        $org = $this->org();

        $session = $org->credits()->in('session');

        // The whole point. On a rolling window the row IS the clock, so a page that
        // shows a balance would spend five hours of somebody's session to draw itself.
        $this->assertSame(0, DB::table('larameter_windows')->count());
        $this->assertSame(2_000, $session->allowance());
        $this->assertSame(0, $session->used());
        $this->assertNull($session->endsAt(), 'nothing is running yet');
    }

    public function test_a_fixed_window_left_behind_reports_the_grid_it_is_on_now(): void
    {
        Carbon::setTestNow('2026-01-05 10:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 5_000);

        // Three weeks of silence. The row still says the week ended on the 12th.
        Carbon::setTestNow('2026-01-26 10:00:00');

        $weekly = $org->fresh()->credits()->in('weekly');

        $this->assertSame(0, $weekly->used(), 'the old week is over, not carried forward');
        $this->assertTrue($weekly->endsAt()->isFuture(), 'and it ends on the grid, not in the past');
        $this->assertSame('2026-02-02 10:00:00', $weekly->endsAt()->format('Y-m-d H:i:s'));
    }

    public function test_an_expired_rolling_window_has_no_end_until_it_is_used_again(): void
    {
        Carbon::setTestNow('2026-01-05 10:00:00');

        $org = $this->org();
        $org->credits()->charge('thing', credits: 100);

        Carbon::setTestNow('2026-01-05 18:00:00');

        // A session restarts when spending resumes, so it has no end while nobody is
        // spending. Saying otherwise would put a countdown on a clock that is not running.
        $this->assertNull($org->fresh()->credits()->in('session')->endsAt());
    }

    public function test_asking_for_a_window_nobody_declared_says_so(): void
    {
        $org = $this->org();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no window [daily] is declared');

        $org->credits()->in('daily');
    }
}
