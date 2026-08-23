<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Concerns\HasCredits;
use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app calls a tenant. */
class Account extends Model
{
    use HasCredits;

    protected $table = 'accounts';

    protected $guarded = [];
}
