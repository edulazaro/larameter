<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The third kind of thing a plan says, after credits and ceilings: switches.
 *
 * And the third default, in the third direction. The asymmetry is the point, and it is
 * worth stating because getting any of them backwards is a quiet way to give away either
 * the product or the ability to use it:
 *
 *   credits absent   => none        they are what you sell
 *   ceiling absent   => unlimited   a restriction nobody wrote down never applied
 *   feature absent   => off         a feature is something you unlock
 */
class FeatureTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $features): Organization
    {
        config()->set('larameter.plans', ['free' => ['features' => $features]]);
        config()->set('larameter.default_plan', 'free');

        return Organization::create(['name' => 'Acme']);
    }

    public function test_a_switch_the_plan_turns_on(): void
    {
        $this->assertTrue($this->org(['api_access' => true])->planAllows('api_access'));
    }

    public function test_a_switch_the_plan_turns_off(): void
    {
        $this->assertFalse($this->org(['api_access' => false])->planAllows('api_access'));
    }

    public function test_a_feature_nobody_mentioned_is_off(): void
    {
        // The opposite of how ceilings work, and deliberately. Defaulting a feature on
        // would hand the whole product to anyone on a plan somebody forgot to fill in.
        $this->assertFalse($this->org([])->planAllows('white_label'));
    }

    public function test_an_account_with_no_plan_has_no_features(): void
    {
        config()->set('larameter.plans', []);
        config()->set('larameter.default_plan', null);

        $this->assertFalse(Organization::create(['name' => 'Acme'])->planAllows('api_access'));
    }
}
