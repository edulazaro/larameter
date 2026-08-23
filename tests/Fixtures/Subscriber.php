<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use EduLazaro\Larameter\Concerns\HasCredits;
use EduLazaro\Larameter\Concerns\HasPlans;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for a Cashier billable.
 *
 * Cashier itself is not installed here, so CashierPlanProvider's own guard would skip it
 * and the path it exists for would never run. The tests that need it swap the guard, and
 * this answers the two methods it asks.
 */
class Subscriber extends Model
{
    use HasCredits;
    use HasPlans;

    protected $table = 'organizations';

    protected $guarded = [];

    public ?string $subscribedPrice = null;

    public function subscribed(string $type = 'default'): bool
    {
        return $this->subscribedPrice !== null;
    }

    public function subscribedToPrice(string $price, string $type = 'default'): bool
    {
        return $this->subscribedPrice === $price;
    }

    public function subscription(string $type = 'default'): mixed
    {
        return null;
    }
}
