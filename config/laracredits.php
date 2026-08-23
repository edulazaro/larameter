<?php

/*
| env() is called in THIS file and nowhere else in the package: with config:cache
| Laravel skips loading .env entirely, so an env() call outside a config file quietly
| returns its default from then on.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Plan limits
    |--------------------------------------------------------------------------
    | Your implementation of Contracts\ProvidesPlanLimits. Plans, tiers, the deal one
    | customer negotiated: all yours. The package only asks how much of X an account
    | gets. Leave it null and everything is unlimited, which is what you want before
    | you have wired your pricing.
    */

    'plan_limits' => null,

    /*
    |--------------------------------------------------------------------------
    | Windows
    |--------------------------------------------------------------------------
    | The monthly budget is what you sell. The weekly one is a brake: without it an
    | account can burn a month's allowance in a bad afternoon and spend the rest of it
    | locked out, which reads as the product being broken rather than the plan being
    | small.
    */

    'weekly_share' => 0.25,

    /*
    |--------------------------------------------------------------------------
    | Fixed prices
    |--------------------------------------------------------------------------
    | What an action costs, by name. Anything not listed is free: a package should not
    | invent a price for something you never priced.
    */

    'prices' => [
        // 'create_form' => 1,
        // 'send_email' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metered rates
    |--------------------------------------------------------------------------
    | Cost per unit, by unit and then by operation. '*' is the fallback within a unit.
    | `per` is how many units the prices refer to, so token rates read the way the
    | providers publish them.
    |
    | Keys are indexed directly, NOT through dot notation: a name with a dot in it
    | (gpt-5.4) gets split by config() and silently falls through to the fallback,
    | which undercharges.
    */

    'rates' => [
        // 'token' => [
        //     'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'per' => 1_000_000],
        //     '*'      => ['in' => 5.00, 'out' => 15.00, 'per' => 1_000_000],
        // ],
    ],

    // How many credits one unit of cost buys. With cost in USD, 10000 makes a credit
    // a hundredth of a cent.
    'credits_per_unit_cost' => 10000,

    // What an unpriced unit costs, so metering an unknown model is not free. The gap
    // would otherwise only surface on the provider's invoice.
    'fallback_units_per_credit' => 100,

];
