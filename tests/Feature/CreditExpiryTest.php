<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Credits that die on a date.
 *
 * A balance with no expiry is a liability you carry forever, and one somebody can spend
 * in three years at a price that no longer covers what it costs to serve. The awkward
 * part is not the date, it is that a balance kept as one number cannot tell which half of
 * it is old. So a positive deposit became a lot, and the balance became the sum of the
 * lots still standing.
 */
class CreditExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', ['week' => ['days' => 7, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 0]]);
        config()->set('larameter.default_plan', 'free');
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme']);
    }

    /**
     * The default has to be exactly what the package did before this existed, or every
     * deposit already written changes meaning on upgrade.
     */
    public function test_a_deposit_with_no_date_never_expires(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500);

        $this->travel(10)->years();

        $this->assertSame(500, $org->credits()->remaining());
    }

    /**
     * And this is the whole feature: the clause is in the balance, so a lot stops
     * counting the moment its date passes. No command to schedule, no compensating row,
     * and no window in which the balance is wrong because the sweep has not run.
     */
    public function test_a_lot_stops_counting_the_moment_it_dies(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500, expiresAt: now()->addDays(30));

        $this->assertSame(500, $org->credits()->remaining());

        $this->travel(31)->days();

        $this->assertSame(0, $org->credits()->remaining());
    }

    public function test_only_the_lot_that_died_is_lost(): void
    {
        $org = $this->org();
        $org->credits()->deposit(300, expiresAt: now()->addDays(10));
        $org->credits()->deposit(700, expiresAt: now()->addDays(90));

        $this->travel(11)->days();

        $this->assertSame(700, $org->credits()->remaining());
    }

    /**
     * Spending what would have been lost anyway is the only order that does not cost the
     * account holder something they paid for.
     */
    public function test_the_lot_that_dies_soonest_is_spent_first(): void
    {
        $org = $this->org();
        $pronto = $org->credits()->deposit(300, expiresAt: now()->addDays(10));
        $tarde = $org->credits()->deposit(700, expiresAt: now()->addDays(90));

        $org->credits()->charge('thing', credits: 250);

        $this->assertSame(250, $pronto->fresh()->consumed);
        $this->assertSame(0, $tarde->fresh()->consumed);
    }

    /**
     * What has no date is not a lot at all: it is in the column, and it is spent after
     * every lot, because it is the one thing that cannot be lost by waiting.
     */
    public function test_what_never_expires_is_spent_after_what_does(): void
    {
        $org = $this->org();
        $siempre = $org->credits()->deposit(500);
        $caduca = $org->credits()->deposit(500, expiresAt: now()->addDays(5));

        $org->credits()->charge('thing', credits: 400);

        $this->assertSame(400, $caduca->fresh()->consumed);
        $this->assertSame(0, $siempre->fresh()->consumed, 'untouched until the dated one runs out');
    }

    /**
     * The two buckets must not overlap, and this is the assertion that says so.
     *
     * A dated deposit IS the balance it added. If the observer also moved the column, the
     * accessor would count it once as a lot and once as a number, and every balance in
     * the application would be double.
     */
    public function test_a_dated_deposit_never_touches_the_column(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500, expiresAt: now()->addYear());

        $account = $org->credits()->account();

        $this->assertSame(0, (int) $account->getAttributes()['purchased_credits']);
        $this->assertSame(500, $account->purchased_credits);
    }

    /**
     * A deposit with no date is still a lot. It simply never dies.
     *
     * Everything written from here on is one, whether it expires or not, so a purchase
     * always knows how much of itself is left and a refund can be charged against the
     * purchase it belongs to.
     */
    public function test_a_deposit_with_no_date_is_still_a_lot(): void
    {
        $org = $this->org();
        $deposito = $org->credits()->deposit(500);

        $this->assertSame(0, $deposito->consumed, 'a lot, not history');
        $this->assertSame(500, $org->credits()->remaining());

        $org->credits()->charge('thing', credits: 200);

        $this->assertSame(200, $deposito->fresh()->consumed);
    }

    /**
     * And the column never grows again.
     *
     * It holds what was there before lots existed and can only drain from here, which is
     * what lets it be dropped one day without anybody noticing.
     */
    public function test_the_column_never_grows_again(): void
    {
        $org = $this->org();
        $org->credits()->deposit(500);
        $org->credits()->deposit(500, expiresAt: now()->addYear());

        $account = $org->credits()->account();

        $this->assertSame(0, (int) $account->getAttributes()['purchased_credits']);
        $this->assertSame(1000, $account->purchased_credits);
    }

    /**
     * The row that predates lots is counted once, out of the column, and never as a lot.
     *
     * This is the whole upgrade path. ALTER TABLE leaves `consumed` null on every row
     * already written, and null is what says "not a lot": its credits are in the column
     * and always were. Count it in both places and every balance doubles.
     */
    public function test_a_row_from_before_lots_existed_is_not_counted_twice(): void
    {
        $org = $this->org();
        $account = $org->credits()->account();

        // Exactamente lo que deja una instalación anterior: la fila, y el saldo aparte.
        Deposit::create(['account_id' => $account->getKey(), 'credits' => 500, 'consumed' => null]);
        $account->setAttribute('purchased_credits', 500);
        $account->save();

        $this->assertSame(500, $account->fresh()->purchased_credits);
    }

    public function test_spending_crosses_from_one_lot_into_the_next(): void
    {
        $org = $this->org();
        $primero = $org->credits()->deposit(300, expiresAt: now()->addDays(10));
        $segundo = $org->credits()->deposit(300, expiresAt: now()->addDays(20));

        $org->credits()->charge('thing', credits: 500);

        $this->assertSame(300, $primero->fresh()->consumed);
        $this->assertSame(200, $segundo->fresh()->consumed);
        $this->assertSame(100, $org->credits()->remaining());
    }

    /**
     * What is spent stays spent when the rest of the lot dies, and what died does not
     * come back.
     */
    public function test_a_half_spent_lot_that_expires_takes_only_what_was_left(): void
    {
        $org = $this->org();
        $org->credits()->deposit(1000, expiresAt: now()->addDays(10));
        $org->credits()->charge('thing', credits: 400);

        $this->assertSame(600, $org->credits()->remaining());

        $this->travel(11)->days();

        $this->assertSame(0, $org->credits()->remaining());
        $this->assertSame(400, Deposit::first()->consumed, 'what was spent stays spent');
    }

    /**
     * A refund is not a lot and will never be spent out of. It has to come out of the
     * lots, or the column and the real balance disagree from that moment on.
     */
    public function test_a_refund_comes_out_of_the_lots(): void
    {
        $org = $this->org();
        $lote = $org->credits()->deposit(500, expiresAt: now()->addDays(30));

        $org->credits()->deposit(-200, reason: 'refund');

        $this->assertSame(300, $org->credits()->remaining());
        $this->assertSame(200, $lote->fresh()->consumed);
    }

    /**
     * Expired credits are not a balance a refund can eat into.
     */
    public function test_a_refund_cannot_reach_a_dead_lot(): void
    {
        $org = $this->org();
        $muerto = $org->credits()->deposit(500, expiresAt: now()->addDays(10));

        $this->travel(11)->days();
        $org->credits()->deposit(-200, reason: 'refund');

        $this->assertSame(0, $muerto->fresh()->consumed);
    }

    /**
     * And spending drains the old column once the lots are gone, so it does empty.
     */
    public function test_spending_falls_through_to_the_old_column(): void
    {
        $org = $this->org();
        $account = $org->credits()->account();

        Deposit::create(['account_id' => $account->getKey(), 'credits' => 500, 'consumed' => null]);
        $account->setAttribute('purchased_credits', 500);
        $account->save();

        $lote = $org->credits()->deposit(300, expiresAt: now()->addYear());

        $org->credits()->charge('thing', credits: 400);

        $this->assertSame(300, $lote->fresh()->consumed, 'the lot goes first');
        $this->assertSame(400, (int) $account->fresh()->getAttributes()['purchased_credits'], 'and the rest off the column');
        $this->assertSame(400, $org->credits()->remaining());
    }
}
