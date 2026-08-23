<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Models\Deposit;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Larameter\Concerns\HasCredits;
use EduLazaro\Larameter\Concerns\HasMeters;
use EduLazaro\Larameter\Concerns\HasPlans;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The trait is the whole public surface, so every method on it needs a test that goes
 * through the trait and not around it.
 *
 * These exist because an audit for untested public methods found five, and one of them
 * was meterCredits: the entry point for metering tokens, exercised only through the class
 * underneath it. A trait method can be broken while everything it delegates to passes.
 */
class TraitApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        config()->set('larameter.windows', ['month' => ['months' => 1, 'anchor' => 'fixed']]);
        config()->set('larameter.plans', [
            'free' => ['name' => 'Free', 'credits_monthly' => 1_000_000],
            'pro' => ['name' => 'Pro', 'price' => 5900, 'credits_monthly' => 5_000_000],
        ]);
        config()->set('larameter.default_plan', 'free');
        config()->set('larameter.override_column', null);
        config()->set('larameter.rates', [
            'gpt-4o' => ['input' => 25_000, 'output' => 100_000],
        ]);

        return Organization::create(['name' => 'Acme']);
    }

    public function test_metering_tokens_through_the_trait(): void
    {
        $org = $this->org();

        $record = $org->usage()->meter('gpt-4o', 'token', 1_000_000, 1_000_000);

        // $2.50 + $10.00 at 10000 credits per unit of cost.
        $this->assertSame(125_000, $record->credits);
        $this->assertSame('token', $record->unit);
        $this->assertSame('gpt-4o', $record->operation);
    }

    public function test_metering_records_who_and_what_it_was_about(): void
    {
        $org = $this->org();
        $actor = $this->org();
        $subject = $this->org();

        $record = $org->usage()->meter('gpt-4o', 'token', 1_000, 0, $actor, $subject);

        $this->assertTrue($record->actor->is($actor));
        $this->assertTrue($record->subject->is($subject));
    }

    public function test_asking_which_plan_it_is_on(): void
    {
        $org = $this->org();

        $this->assertTrue($org->plan()->is('free'));
        $this->assertFalse($org->plan()->is('pro'));
    }

    public function test_the_plan_carries_whatever_else_you_wrote_in_it(): void
    {
        $org = $this->org();
        $org->usage()->setPlan('pro');

        $this->assertSame(5900, $org->plan()->price);
        $this->assertSame('Pro', $org->plan()->name);
    }

    public function test_the_history_hangs_off_the_account(): void
    {
        $org = $this->org();
        $org->usage()->deposit(500);
        $org->usage()->charge('thing', credits: 10);

        $account = $org->usage()->account();

        $this->assertCount(1, $account->deposits);
        $this->assertCount(1, $account->usage);
        $this->assertInstanceOf(Deposit::class, $account->deposits->first());
        $this->assertInstanceOf(UsageRecord::class, $account->usage->first());
    }

    public function test_the_traits_bring_three_doors_and_little_else(): void
    {
        // Every public name a trait brings is a name the host model can no longer use,
        // and a clash between two traits is a fatal error when the class is compiled.
        // So the reading API is three doors, and anything added here is a deliberate
        // decision rather than a method that drifted onto somebody else's class.
        $brought = [];

        foreach ([HasCredits::class, HasPlans::class, HasMeters::class] as $trait) {
            foreach ((new ReflectionClass($trait))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $brought[] = $method->getName();
            }
        }

        sort($brought);

        $this->assertSame([
            'bootHasMeters',           // Eloquent calls it; not ours to name
            'flushPlanProviders',      // static, for tests
            'flushRegisteredMeters',   // static, for tests
            'forgetPlan',              // after a subscription changes mid-request
            'meter',                   // static, registers a meter from outside
            'meterAccount',            // the relation, so with() can preload it
            'plan',                    // <- door
            'quota',                   // <- door
            'setPlanProviders',        // static
            'usage',                   // <- door
        ], $brought);
    }
}
