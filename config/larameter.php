<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Windows
    |--------------------------------------------------------------------------
    | An allowance is always an allowance PER something. Declare here what those
    | somethings are; a plan then says how many credits it grants in each.
    |
    | Length is built from `minutes`, `hours`, `days` and `months`, combined. Weeks are
    | not a unit here: use days, so there is one obvious way to write seven of them.
    |
    | `anchor` decides when the NEXT window starts.
    |
    |   rolling  starts the moment credits are next spent after the old one expired, so
    |            the full length is always available. What you want for a session: with a
    |            fixed grid, starting ten minutes before a boundary would hand somebody
    |            ten minutes and read as the product having robbed them.
    |
    |   fixed    sits on a grid laid down from the first window and moves on whether it
    |            is used or not. What you want for a week, because "when does my week
    |            reset" needs an answer that is not "depends when you last stopped".
    |
    | Leave this empty and nothing constrains an account but what it has purchased.
    */

    'windows' => [
        'session' => ['minutes' => 300, 'anchor' => 'rolling'],
        'week' => ['days' => 7, 'anchor' => 'fixed'],
        'month' => ['months' => 1, 'anchor' => 'fixed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    | `credits` is the allowance per window. `limits` are ceilings on how many of
    | something the plan allows, which you check yourself with canCreate().
    |
    | Two defaults that read in opposite directions, on purpose:
    |
    |   - no `credits` at all means NO allowance. Credits are what you sell, so a plan
    |     that does not mention them does not include any.
    |   - a window MISSING from a `credits` map that exists does not constrain that plan.
    |     Saying 'week' => 2000 and nothing else means limited weekly, session free.
    |   - a `limits` key you never listed is unlimited. These are restrictions, and a
    |     package you just installed should not refuse to create users on its own opinion.
    |
    | -1 is unlimited anywhere, which is not the same as 0.
    |
    | Plans are OPTIONAL. An account with no plan is valid and spends purchased credits
    | only, which is what you want if you sell bundles rather than subscriptions.
    */

    'plans' => [
        'free' => [
            // Switches this plan turns on. Absent means off: a feature is something you
            // unlock, so defaulting it on would give the product away on any plan you
            // forgot to fill in. Read with $org->planAllows('api_access').
            'features' => [
                // 'api_access' => false,
                // 'white_label' => false,
            ],

            // The weekly at half the monthly. What that ratio really decides is how much
            // somebody may concentrate: at 50% they can burn the month in two weeks, at
            // 25% they need all four.
            //
            // Set it too tight and the monthly number stops being what they actually get.
            // People do not work evenly spread: they do the bulk of it the week before a
            // deadline and then go quiet. Squeeze the week and that customer never reaches
            // their month, so the figure you sold them is not the figure they receive.
            'credits' => ['session' => 100, 'week' => 500, 'month' => 1_000],
        ],

        // The weekly cap is optional in two ways. Drop the window from `windows` above and
        // it exists for nobody; leave it declared but out of a plan's `credits` and that
        // plan alone goes uncapped weekly, which is how a top tier gets no brake while the
        // cheaper ones keep theirs.
        //
        // 'hyper' => [
        //     'credits' => ['session' => 5_000, 'month' => 100_000],
        //     'limits' => ['seats' => -1],
        // ],
    ],

    // What an account falls back to when nothing else resolves one. null for none, if
    // credits are only ever bought.
    'default_plan' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Where the plans live
    |--------------------------------------------------------------------------
    | Point this at your own file and a plan is defined once, in one place, with the
    | commercial half beside the metered half. The package reads `credits`, `limits` and
    | `features` and ignores everything else, so your name, price and whatever else you
    | keep in there stays where it belongs.
    |
    |     'plans_from' => 'plans',        // config/plans.php
    |     'plans_from' => 'plans.tiers',  // nested, if that file holds other things too
    */

    'plans_from' => 'larameter.plans',

    /*
    |--------------------------------------------------------------------------
    | Working out which plan an account is on
    |--------------------------------------------------------------------------
    | Three sources, in this order, and the order is the logic:
    |
    |   1. `override_column` on the model, for courtesy accounts, demos and partners. It
    |      beats the subscription on purpose: a person decided it and Stripe should not
    |      undo it. Null to switch that off.
    |   2. The Cashier subscription, matched by the price id under `price_id_key`. Cashier
    |      is detected, never required: without it this step is skipped.
    |   3. Whatever setCreditPlan() stored, then `default_plan`.
    |
    | An app that sells credit bundles and no subscriptions only ever reaches step 3, and
    | none of this costs it anything.
    */

    'override_column' => null,
    'price_id_key' => 'stripe_price_id',
    'subscription_type' => 'default',

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
