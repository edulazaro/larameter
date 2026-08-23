<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\UsageTracker;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Pricing: turning an action or a pile of tokens into a number of credits. */
class UsageTrackerTest extends TestCase
{
    use RefreshDatabase;

    private UsageTracker $meter;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('larameter.windows', ['week' => ['days' => 7, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 1_000_000]]);
        config()->set('larameter.default_plan', 'free');

        $this->meter = app(UsageTracker::class);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme']);
    }

    public function test_a_fixed_action_costs_what_you_priced_it_at(): void
    {
        config()->set('larameter.prices', ['create_form' => 5]);

        $record = $this->meter->charge($this->org(), 'create_form');

        $this->assertSame(5, $record->credits);
        $this->assertSame('action', $record->unit);
    }

    public function test_an_unpriced_action_is_free_rather_than_a_guess(): void
    {
        config()->set('larameter.prices', []);

        $this->assertSame(0, $this->meter->charge($this->org(), 'something_else')->credits);
    }

    public function test_metered_units_are_priced_by_operation(): void
    {
        config()->set('larameter.rates', [
            'gpt-4o' => ['input' => 25_000, 'output' => 100_000],
        ]);

        $record = $this->meter->meter($this->org(), 'gpt-4o', 'token', 1_000_000, 1_000_000);

        // 25.000 in + 100.000 out, one million tokens of each.
        $this->assertSame(125_000, $record->credits);
    }

    public function test_a_model_name_with_a_dot_is_not_split_by_config_dot_notation(): void
    {
        // The bug this guards: config('larameter.rates.gpt-5.4') reads rates > gpt-5 > 4,
        // finds nothing, falls through to the wildcard and undercharges. It only ever
        // shows up on the provider's invoice.
        config()->set('larameter.rates', [
            'gpt-5.4' => ['input' => 1_000_000, 'output' => 1_000_000],
            '*' => ['input' => 100, 'output' => 100],
        ]);

        $record = $this->meter->meter($this->org(), 'gpt-5.4', 'token', 1_000_000);

        $this->assertSame(1_000_000, $record->credits);
    }

    public function test_an_unknown_model_still_costs_something(): void
    {
        config()->set('larameter.rates', []);
        config()->set('larameter.fallback_units_per_credit', 100);

        $record = $this->meter->meter($this->org(), 'some-new-model', 'token', 500, 500);

        $this->assertSame(10, $record->credits);
    }

    public function test_spend_can_be_traced_back_to_what_caused_it(): void
    {
        $org = $this->org();
        $actor = $this->org();
        $subject = $this->org();

        $record = $this->meter->charge($org, 'export', $actor, $subject);

        $this->assertTrue($record->actor->is($actor));
        $this->assertTrue($record->subject->is($subject));
        $this->assertTrue($record->account->meterable->is($org));
    }

    public function test_quantity_caps_are_a_different_question_from_credits(): void
    {
        config()->set('larameter.plans', ['free' => [
            'credits_monthly' => 100,
            'limits' => ['members' => 5],
        ]]);

        $org = $this->org();

        // Ceilings moved to HasMeters, which counts them itself instead of making the
        // caller pass a number it might forget to work out.
        $this->assertTrue($org->quota()->allows('members'));
        $this->assertTrue($org->quota()->allows('anything_with_no_meter'));
    }

    public function test_the_memo_answers_once_and_does_not_notice_later_spending(): void
    {
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 10]]);

        $org = $this->org();

        $this->assertTrue($this->meter->hasCreditsMemoized($org));

        $this->meter->charge($org, 'thing', credits: 10);

        // Deliberate: a turn that started with credit finishes. The unmemoised call sees
        // the truth, and the next request gets a fresh meter.
        $this->assertTrue($this->meter->hasCreditsMemoized($org));
        $this->assertFalse($this->meter->hasCredits($org));
    }

    public function test_a_metered_call_can_be_priced_without_being_charged(): void
    {
        config()->set('larameter.rates', ['gpt-4o' => ['input' => 25_000, 'output' => 100_000]]);

        $org = $this->org();

        // Same arithmetic charging would do, but nothing is written: a caller that has
        // to show or store what a call cost should not have to recompute the table.
        $this->assertSame(125_000, $org->credits()->meterPrice('gpt-4o', 1_000_000, 1_000_000));
        $this->assertSame(0, $org->credits()->records()->count());
    }
}
