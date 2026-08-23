<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * A balance is the sort of thing that ends up in a column of a listing, so reading one
 * has to survive being done fifty times on a page.
 */
class EagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_listing_that_preloads_the_account_runs_no_queries_to_read_balances(): void
    {
        config()->set('larameter.windows', ['week' => ['days' => 7, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 100]]);
        config()->set('larameter.default_plan', 'free');

        foreach (range(1, 5) as $i) {
            Organization::create(['name' => "org {$i}"])->chargeCredits('thing', credits: 5);
        }

        $orgs = Organization::with('meterAccount.windows')->get();

        DB::enableQueryLog();

        foreach ($orgs as $org) {
            $this->assertSame(95, $org->creditsRemaining());
        }

        // The with() the caller wrote has to actually do something. It did not until
        // UsageTracker stopped going straight to Account::for() and ignoring it.
        $this->assertSame([], DB::getQueryLog());
    }
}
