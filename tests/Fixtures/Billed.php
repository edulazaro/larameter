<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

use Illuminate\Support\Collection;

/**
 * What Cashier's Billable brings to a model, reduced to the one method that matters
 * here: its own meters(), which lists Stripe's billing meters.
 *
 * Reproduced rather than depended on, so the suite does not need Cashier installed to
 * hold the line. The signature is copied from Laravel\Cashier\Concerns\ManagesUsageBilling.
 */
trait Billed
{
    /**
     * Stripe's billing meters.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $requestOptions
     * @return Collection
     */
    public function meters(array $options = [], array $requestOptions = []): Collection
    {
        return new Collection(['stripe']);
    }
}
