<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    | Name your plans and what each one includes.
    |
    | `credits_monthly` is the only key the package reads by name: it is the allowance an
    | account gets each period. Everything else is a ceiling you check yourself with
    | canCreate(), and -1 means unlimited, which is not the same as 0.
    |
    | Plans are OPTIONAL. An account with no plan is valid and spends purchased credits
    | only, which is what you want if you sell bundles rather than subscriptions.
    */

    'plans' => [
        'free' => [
            'credits_monthly' => 100,
        ],

        // 'pro' => [
        //     'credits_monthly' => 10_000,
        //     'max_users' => 25,
        // ],
    ],

    // What a brand new account starts on. null for none, if credits are only ever bought.
    'default_plan' => 'free',

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
    | Cost of what you meter, by the name of the thing being priced. For an LLM call that
    | is the model, so this table reads the way the providers publish theirs.
    |
    | `in` and `out` are the price of what goes in and what comes back, and `per` is how
    | many units those prices refer to. Providers quote per million tokens, so you copy
    | their numbers unchanged.
    |
    | '*' is the fallback for anything not listed.
    |
    | Keys are indexed directly, NOT through dot notation: a name with a dot in it
    | (gpt-5.4) gets split by config() and silently falls through to the fallback, which
    | undercharges and only shows up on the provider's invoice.
    */

    'rates' => [
        // 'gpt-4o'      => ['in' => 2.50, 'out' => 10.00, 'per' => 1_000_000],
        // 'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60,  'per' => 1_000_000],
        // '*'           => ['in' => 5.00, 'out' => 15.00, 'per' => 1_000_000],
    ],

    // How many credits one unit of cost buys. With cost in USD, 10000 makes a credit a
    // hundredth of a cent.
    'credits_per_unit_cost' => 10000,

    // What an unpriced unit costs, so metering an unknown model is not free. The gap would
    // otherwise only surface on the provider's invoice.
    'fallback_units_per_credit' => 100,

];
