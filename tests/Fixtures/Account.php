<?php

namespace EduLazaro\Laracredits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app calls a tenant. */
class Account extends Model
{
    protected $table = 'accounts';

    protected $guarded = [];
}
