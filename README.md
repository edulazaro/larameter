# Larameter

Credit metering and plan limits for Laravel. Charge per action or per unit consumed, cap
by month and by week, and stop a call before it spends what an account no longer has.

Nothing to do with AI in particular. The app this came from charges credits for creating a
form, generating a document, running a poll, verifying an identity and sending an email,
none of which involve a model. That is exactly why it is not part of an AI package: you
should not have to install one to meter a form.

## How it works

There is **no stored balance**. The balance is the usage table summed over a window and
compared against the plan. That is a deliberate trade: a stored balance is one number that
can drift out of step with the rows that produced it, and when it does you cannot tell
which is wrong. Summing is slower and always right, and the index carries it at the volumes
one account produces. Outgrow that and the fix is a periodic rollup row, not a mutable
balance.

**Two windows, the tighter one wins.** The monthly budget is what you sell; the weekly one,
a quarter of it by default, is a brake. Without it an account can burn a month's allowance
in a bad afternoon and spend the other three weeks locked out, which reads as the product
being broken rather than the plan being small.

## Setup

Three steps, no interface to implement.

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

Done:

    $org->hasCredits();
    $org->chargeCredits('create_form');
    $org->creditsRemaining();
    $org->canCreate('seats', $current);

The plan comes from a `plan` column, or override `creditPlanKey()` if yours lives on a
subscription row or behind a relation. A limit key you never listed is **unlimited**, not
zero: the other way round, a package you just installed starts refusing things you never
meant to limit.

**Plans somewhere else?** In a table, with per-customer overrides, a deal somebody
negotiated over the phone? Implement `Contracts\ProvidesPlanLimits` and name the class in
the config. Most apps never need this.

## Charging

    $meter->charge($account, 'create_form');                    fixed price by name
    $meter->meter($account, 'gpt-4o', 'token', $in, $out);      priced per unit

An action you never priced is **free**, not guessed at. A metered unit you never priced
still costs something, because the alternative is that metering an unknown model is free
and the gap only surfaces on your provider's invoice.

## Asking

    $meter->hasCredits($account)          may it spend?
    $meter->remaining($account)           how much is left, tighter window
    $meter->limitHit($account)            'weekly', 'monthly' or null
    $meter->canCreate($account, 'seats', $current)   ceilings, not spend

`hasCreditsMemoized()` answers once per instance, for hot paths that ask repeatedly. It
does not notice spending that happens afterwards, deliberately: a turn that starts with
credit finishes, and the overshoot is bounded to one turn.

## With laragents

`laragents` defines a `UsageRecorder` contract and calls it after every model call.
Implementing it over this package is a few lines, and neither package requires the other.

## License

MIT.
