<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Concerns\HasCredits;
use EduLazaro\Larameter\Concerns\HasMeters;
use EduLazaro\Larameter\Concerns\HasPlans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model billed through Cashier, which is the ordinary case for anything on a plan.
 *
 * Declaring this class is itself the test: two traits claiming one method name is a
 * fatal error when the class is compiled, not something a test can catch.
 */
class BilledOrganization extends Model
{
    use HasCredits;
    use HasPlans;
    use HasMeters;
    use Billed;

    protected $table = 'organizations';

    protected $guarded = [];

    protected array $meters = [
        MemberMeter::class,
    ];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'organization_id');
    }
}
