<?php

namespace EduLazaro\Laracredits\Tests\Fixtures;

use EduLazaro\Laracredits\Concerns\HasCredits;
use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app calls a tenant. */
class Account extends Model
{
    use HasCredits;

    protected $table = 'accounts';

    protected $guarded = [];
}
