<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Concerns\HasCredits;
use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app bills: an org, a user, a workspace. */
class Organization extends Model
{
    use HasCredits;

    protected $table = 'organizations';

    protected $guarded = [];
}
