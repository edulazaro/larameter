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
        config()->set('larameter.plans', ['free' => ['credits' => ['week' => 1_000_000]]]);
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
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'per' => 1_000_000],
        ]);

        $record = $this->meter->meter($this->org(), 'gpt-4o', 'token', 1_000_000, 1_000_000);

        // 2.50 + 10.00 = 12.50 USD, at 10000 credits per unit of cost.
        $this->assertSame(125_000, $record->credits);
        $this->assertSame('12.500000', $record->cost);
    }

    public function test_a_model_name_with_a_dot_is_not_split_by_config_dot_notation(): void
    {
        // The bug this guards: config('larameter.rates.gpt-5.4') reads rates > gpt-5 > 4,
        // finds nothing, falls through to the wildcard and undercharges. It only ever
        // shows up on the provider's invoice.
        config()->set('larameter.rates', [
            'gpt-5.4' => ['in' => 100.00, 'out' => 100.00, 'per' => 1_000_000],
            '*' => ['in' => 0.01, 'out' => 0.01, 'per' => 1_000_000],
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
            'credits' => ['week' => 100],
            'limits' => ['max_users' => 3],
        ]]);

        $org = $this->org();

        $this->assertTrue($this->meter->canCreate($org, 'max_users', 2));
        $this->assertFalse($this->meter->canCreate($org, 'max_users', 3));

        // A ceiling you never wrote down was never meant to apply.
        $this->assertTrue($this->meter->canCreate($org, 'max_projects', 9999));
    }

    public function test_the_memo_answers_once_and_does_not_notice_later_spending(): void
    {
        config()->set('larameter.plans', ['free' => ['credits' => ['week' => 10]]]);

        $org = $this->org();

        $this->assertTrue($this->meter->hasCreditsMemoized($org));

        $this->meter->charge($org, 'thing', credits: 10);

        // Deliberate: a turn that started with credit finishes. The unmemoised call sees
        // the truth, and the next request gets a fresh meter.
        $this->assertTrue($this->meter->hasCreditsMemoized($org));
        $this->assertFalse($this->meter->hasCredits($org));
    }
}
