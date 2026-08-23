<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Attributes\MeteredBy;
use EduLazaro\Larameter\Concerns\HasCredits;
use EduLazaro\Larameter\Concerns\HasMeters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Declares its meters with the attribute instead of the property. */
#[MeteredBy(MemberMeter::class)]
class Workspace extends Model
{
    use HasCredits;
    use HasMeters;

    protected $table = 'organizations';

    protected $guarded = [];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'organization_id');
    }
}
