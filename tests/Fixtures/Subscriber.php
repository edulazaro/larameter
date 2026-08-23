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

    /**
     * Keyed by id, because a real Cashier subscription lives in the database and survives
     * being loaded again. An instance property would not: the observer loads the account
     * by id and gets a fresh model, which is exactly where this used to fall apart.
     *
     * @var array<int, array{price: ?string, subscription: mixed}>
     */
    public static array $subscriptions = [];

    public static function subscribeFake(int $id, ?string $price, mixed $subscription = null): void
    {
        static::$subscriptions[$id] = ['price' => $price, 'subscription' => $subscription];
    }

    public function subscribed(string $type = 'default'): bool
    {
        return (static::$subscriptions[$this->id]['price'] ?? null) !== null;
    }

    public function subscribedToPrice(string $price, string $type = 'default'): bool
    {
        return (static::$subscriptions[$this->id]['price'] ?? null) === $price;
    }

    public function subscription(string $type = 'default'): mixed
    {
        return static::$subscriptions[$this->id]['subscription'] ?? null;
    }
}
