# Larameter

Credit metering and plans for Laravel. Sell an allowance, sell top-ups, charge per action
or per unit consumed, and stop a call before it spends what an account no longer has.

Nothing to do with AI in particular. The app this came from charges credits for creating a
form, generating a document, running a poll, verifying an identity and sending an email,
none of which involve a model. That is exactly why it is not part of an AI package: you
should not have to install one to meter a form.

## Two tables

**`larameter_accounts`** is the balance, one row per thing you bill, and the source of
truth for what may still be spent. It holds the plan, what the plan allowance has already
covered this period, and credits bought on top.

**`larameter_usage`** is the detail, append-only. What you audit with, invoice from, and
reconcile against if the two ever disagree. Deleting from it does not hand anybody their
credits back, which is the right way round.

The balance is stored rather than summed, and once you sell credits that is not an
optimisation but the model itself: a top-up is not consumption, so it cannot be expressed
as a sum of what was spent.

**Two buckets, and the allowance goes first.** The plan allowance resets every period;
purchased credits accumulate and survive it. Spending draws on the allowance before it
touches anything bought, so what somebody paid for does not evaporate at the turn of a
month.

## Setup

Three steps. No interface to implement, nothing to bind.

    composer require edulazaro/larameter
    php artisan vendor:publish --tag=larameter-config
    php artisan vendor:publish --tag=larameter-migrations

Name your plans in the config:

    'plans' => [
        'free' => ['credits_monthly' => 100],
        'pro'  => ['credits_monthly' => 10000, 'seats' => 10],
    ],

Add the trait to whatever you bill:

    class Organization extends Model
    {
        use EduLazaro\Larameter\Concerns\HasCredits;
    }

Done. No column on your table, no migration of your own: the account row appears the first
time you touch it.

    $org->hasCredits();
    $org->chargeCredits('create_form');
    $org->meterCredits('gpt-4o', 'token', $in, $out);
    $org->creditsRemaining();
    $org->canCreate('seats', $current);

## Plans

Plans are optional. An account with no plan is valid and spends purchased credits only,
which is what you want if you sell bundles rather than subscriptions.

    $org->setCreditPlan('pro');    // your subscription changed
    $org->setCreditPlan(null);     // cancelled
    $org->addCredits(5_000);       // they bought a bundle

The package cannot know when your plan changes, so it does not guess. Call `setCreditPlan`
from wherever you do know: an observer on Cashier's `Subscription` model, an admin action,
a grant. Be aware that a **trial lapsing fires no event at all**, so if your plans can
expire by the clock alone, something has to notice.

Changing plan does not restart the period. An upgrade raises the ceiling over what has
already been spent, rather than handing a second allowance to whoever works out they can
upgrade and downgrade in the same afternoon.

`credits_monthly` is the only key read by name. Everything else is a ceiling you check with
`canCreate()`, and there the defaults are deliberately asymmetric: a **credits** key you
never listed is **zero**, because credits are what you sell and a plan that does not
mention them does not include any; a **ceiling** you never listed is **unlimited**, because
a package you just installed should not start refusing to create users on its own opinion.

## Charging

    $org->chargeCredits('create_form');                  fixed price by name
    $org->meterCredits('gpt-4o', 'token', $in, $out);    priced per unit

An action you never priced is **free**, not guessed at. A metered unit you never priced
still costs something, because the alternative is that metering an unknown model is free
and the gap only surfaces on your provider's invoice.

Rates are indexed directly and not through dot notation, so a model with a dot in its name
(`gpt-5.4`) is priced as itself rather than silently falling through to the wildcard.

Writing a usage row by any other means still moves the balance: an observer keeps the two
in step, so a backfill or a console command cannot record consumption nobody is charged
for.

## Asking

    $org->hasCredits()            may it spend?
    $org->creditsRemaining()      allowance left, plus what was bought
    $org->creditBudget()          what the plan grants per period
    $org->creditPlan()            the plan key, or null

`CreditMeter::hasCreditsMemoized()` answers once per instance, for hot paths that ask
repeatedly. It does not notice spending that happens afterwards, deliberately: a turn that
starts with credit finishes, and the overshoot is bounded to one turn. The binding is
`scoped`, not a singleton, so a queue worker does not keep one turn's answer alive across
every job it goes on to process.

## With laragents

`laragents` defines a `UsageRecorder` contract and calls it after every model call. Point
`laragents.usage_recorder` at `'larameter'` and the two are wired together with no adapter
to write. Neither package requires the other.

## License

MIT.
