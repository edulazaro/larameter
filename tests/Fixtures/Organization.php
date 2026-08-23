<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Concerns\HasCredits;
use EduLazaro\Larameter\Concerns\HasMeters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stands in for whatever the host app bills: an org, a user, a workspace. */
class Organization extends Model
{
    use HasCredits;
    use HasMeters;

    protected $table = 'organizations';

    protected $guarded = [];

    protected array $meters = [
        MemberMeter::class,
        CaseMeter::class,
    ];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
