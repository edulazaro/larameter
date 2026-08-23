<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\Fixtures\CaseMeter;
use EduLazaro\Larameter\Tests\Fixtures\Matter;
use EduLazaro\Larameter\Tests\Fixtures\Member;
use EduLazaro\Larameter\Tests\Fixtures\MemberMeter;
use EduLazaro\Larameter\Tests\Fixtures\Organization;
use EduLazaro\Larameter\Tests\Fixtures\ProjectMeter;
use EduLazaro\Larameter\Tests\Fixtures\Workspace;
use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Ceilings on how many of something may exist. A different question from credits: those
 * are spent and come back, these are a standing count the plan caps.
 *
 * The reason a meter is a class and not a number passed in by the caller: the app this
 * came from had a plan limit of one seat, showed it on the usage screen, and never
 * checked it when inviting. The cap was enforced in one place and forgotten in another,
 * because enforcing it required the caller to remember to count.
 */
class MeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::flushRegisteredMeters();
        Workspace::flushRegisteredMeters();
    }

    private function org(array $limits = ['members' => 2, 'cases' => -1]): Organization
    {
        config()->set('larameter.plans', ['free' => ['credits_monthly' => 0, 'limits' => $limits]]);
        config()->set('larameter.default_plan', 'free');

        return Organization::create(['name' => 'Acme']);
    }

    public function test_the_declared_list_is_all_there_is_to_it(): void
    {
        $org = $this->org();

        $this->assertInstanceOf(MemberMeter::class, $org->meterFor('members'));
        $this->assertInstanceOf(CaseMeter::class, $org->meterFor('cases'));
    }

    public function test_it_counts_without_anybody_passing_a_number(): void
    {
        $org = $this->org();
        Member::create(['organization_id' => $org->id]);
        Member::create(['organization_id' => $org->id]);

        $this->assertSame(2, $org->meterFor('members')->count());
    }

    public function test_the_cap_holds(): void
    {
        $org = $this->org(['members' => 2]);

        $this->assertTrue($org->canCreate('members'));

        Member::create(['organization_id' => $org->id]);
        Member::create(['organization_id' => $org->id]);

        $this->assertFalse($org->fresh()->canCreate('members'));
    }

    public function test_minus_one_is_unlimited_and_zero_is_none(): void
    {
        $unlimited = $this->org(['members' => -1]);
        $this->assertTrue($unlimited->canCreate('members'));

        $none = $this->org(['members' => 0]);
        $this->assertFalse($none->canCreate('members'));
    }

    public function test_asking_for_several_at_once_is_answered_for_several(): void
    {
        $org = $this->org(['members' => 3]);
        Member::create(['organization_id' => $org->id]);

        $this->assertTrue($org->canCreate('members', 2));
        $this->assertFalse($org->canCreate('members', 3));
    }

    public function test_a_resource_with_no_meter_is_unlimited(): void
    {
        // The other way round, a package you just installed would start refusing to
        // create things it was never told to count.
        $this->assertTrue($this->org()->canCreate('projects'));
    }

    public function test_the_class_name_says_the_handle_when_you_do_not(): void
    {
        $org = $this->org();

        // The suffix comes off before the word is pluralised. The other way round gives
        // invitation_meters, which matches no plan limit and so reads as unlimited: a cap
        // that silently never applies.
        $this->assertSame('invitations', (new \EduLazaro\Larameter\Tests\Fixtures\InvitationMeter($org))->handle);

        // Already plural stays put, and an irregular plural is still irregular.
        $this->assertSame('companies', (new \EduLazaro\Larameter\Tests\Fixtures\CompaniesMeter($org))->handle);
    }

    public function test_the_label_comes_from_the_key_unless_you_say_otherwise(): void
    {
        $org = $this->org();

        $this->assertSame('Members', $org->meterFor('members')->label());
        $this->assertSame('members', $org->meterFor('members')->handle);
        $this->assertSame('Expedientes', $org->meterFor('cases')->label());
    }

    public function test_the_usage_screen_builds_itself(): void
    {
        $org = $this->org(['members' => 2, 'cases' => -1]);
        Member::create(['organization_id' => $org->id]);
        Matter::create(['organization_id' => $org->id]);
        Matter::create(['organization_id' => $org->id]);

        $this->assertSame([
            ['handle' => 'members', 'label' => 'Members', 'count' => 1, 'limit' => 2],
            ['handle' => 'cases', 'label' => 'Expedientes', 'count' => 2, 'limit' => -1],
        ], $org->fresh()->usageSummary());
    }

    public function test_a_meter_can_also_be_added_from_outside_the_class(): void
    {
        // For a model you cannot edit. Declaring it AND registering it must not double
        // it, or the usage screen shows every resource as many times as it was mentioned.
        Organization::meter(MemberMeter::class);

        $this->assertCount(2, $this->org()->meters());
    }

    public function test_the_attribute_declares_the_same_as_the_property(): void
    {
        config()->set('larameter.plans', ['free' => ['limits' => ['members' => 5]]]);
        config()->set('larameter.default_plan', 'free');

        $workspace = Workspace::create(['name' => 'Acme']);

        $this->assertInstanceOf(MemberMeter::class, $workspace->meterFor('members'));
        $this->assertTrue($workspace->canCreate('members'));
    }

    public function test_all_three_ways_combine_without_doubling(): void
    {
        Organization::meter(ProjectMeter::class);
        Organization::meter(MemberMeter::class);

        $keys = array_keys($this->org()->meters());

        sort($keys);
        $this->assertSame(['cases', 'members', 'projects'], $keys);
    }

    public function test_something_registered_from_outside_joins_the_declared_ones(): void
    {
        Organization::meter(ProjectMeter::class);

        $keys = array_keys($this->org()->meters());

        $this->assertContains('members', $keys, 'declared on the model');
        $this->assertContains('projects', $keys, 'added from outside');
    }
}
