<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

/**
 * Stands in for a Cashier subscription.
 *
 * Cashier keeps the period dates on Stripe and not in its own table, so CashierPlan has
 * to go and ask. This answers that call without a network.
 */
class FakeSubscription
{
    public function __construct(
        public ?int $periodStart = null,
        public ?int $periodEnd = null,
        public bool $trialing = false,
        public bool $canceledFlag = false,
        public bool $explode = false,
    ) {}

    public function onTrial(): bool
    {
        return $this->trialing;
    }

    public function canceled(): bool
    {
        return $this->canceledFlag;
    }

    public function asStripeSubscription(): object
    {
        if ($this->explode) {
            throw new \RuntimeException('Stripe is having a day');
        }

        return (object) [
            'current_period_start' => $this->periodStart,
            'current_period_end' => $this->periodEnd,
        ];
    }
}
