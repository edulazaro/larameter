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
    | `share` is what part of a plan's allowance fits in this window. A plan grants one
    | figure and every window takes a slice of it, so raising a plan raises all of its
    | windows at once and none can be left behind. A window with no share narrows nothing.
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
        'session' => ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.04],
        'weekly' => ['days' => 7, 'anchor' => 'fixed', 'share' => 0.25],
        'monthly' => ['months' => 1, 'anchor' => 'fixed', 'share' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    | One figure per plan, under the key named by `credits_key`. Every window takes its
    | share of it, so there is one number to keep right rather than one per window.
    |
    | `limits` are ceilings on how many of something exists: seats, projects. Those are
    | counted by meters, not spent.
    |
    | `features` are switches the plan turns on.
    |
    | Three defaults that read in different directions, on purpose:
    |
    |   - no allowance key at all means NO credits. Credits are what you sell.
    |   - a `limits` key you never listed is unlimited. A restriction nobody wrote down
    |     was never meant to apply.
    |   - a `features` key you never listed is off. A feature is something you unlock.
    |
    | -1 is unlimited anywhere, which is not the same as 0.
    |
    | Plans are OPTIONAL. An account with no plan is valid and spends purchased credits
    | only, which is what you want if you sell bundles rather than subscriptions.
    */

    'plans' => [
        'free' => [
            'credits_monthly' => 1_000,
        ],

        // 'pro' => [
        //     'credits_monthly' => 50_000,
        //     'limits' => ['members' => 25],
        //     'features' => ['api_access' => true],
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
    | Where a plan comes from
    |--------------------------------------------------------------------------
    | Providers, tried in order, first answer wins. Only models using the HasPlans trait
    | ever ask, so an app that sells credit bundles and nothing else never runs any of it.
    |
    | The order IS the policy. ForcedPlanProvider before CashierPlanProvider means a plan
    | somebody set by hand beats what Stripe thinks, which is what you want for a courtesy
    | account or a partner: a person decided it deliberately.
    |
    | CashierPlanProvider is inert without Cashier installed, so leaving it here costs
    | nothing. Write your own for Paddle, LemonSqueezy or anything else: implement
    | Contracts\PlanProvider and put it in the list.
    |
    | One list for the whole app, because how billing works has one answer per project.
    | Set $planProviders on a model, or call Model::setPlanProviders(), only when one
    | model is billed differently from another.
    */

    'plan_providers' => [
        \EduLazaro\Larameter\PlanProviders\ForcedPlanProvider::class,
        \EduLazaro\Larameter\PlanProviders\CashierPlanProvider::class,
        \EduLazaro\Larameter\PlanProviders\StoredPlanProvider::class,
    ],

    // Read by ForcedPlanProvider: a column of YOURS holding a plan key set by hand.
    // Null switches that provider off.

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
    | What a metered unit costs in credits, by the name of the thing being priced. For a
    | model call that is the model, so this table reads the way the providers publish
    | theirs.
    |
    | `input` and `output` are the credits for a MILLION units in and a million out, which
    | is how providers quote tokens. Anything not priced by the million is a fixed price,
    | so it belongs in `prices` above.
    |
    | '*' is the fallback for anything not listed.
    |
    | Keys are indexed directly, NOT through dot notation: a name with a dot in it
    | (gpt-5.4) gets split by config() and silently falls through to the fallback, which
    | undercharges and only shows up on the provider's bill.
    */

    'rates' => [
        // 'gpt-4o'      => ['input' => 25_000, 'output' => 100_000],
        // 'gpt-4o-mini' => ['input' => 1_500,  'output' => 6_000],
        // '*'           => ['input' => 50_000, 'output' => 150_000],
    ],

    // What an unpriced unit costs, so metering an unknown model is not free. The gap would
    // otherwise only surface on the provider's invoice.
    'fallback_units_per_credit' => 100,

];
