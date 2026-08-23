# Larameter

Credit metering and plans for Laravel. Sell an allowance per session and per week, sell
top-ups on the side, charge per action or per unit consumed, and stop a call before it
spends what an account no longer has.

Nothing to do with AI in particular. The app this came from charges credits for creating a
form, generating a document, running a poll, verifying an identity and sending an email,
none of which involve a model. That is exactly why it is not part of an AI package: you
should not have to install one to meter a form.

## Four tables

    larameter_accounts    what does not expire: the plan, and credits bought on top
    larameter_windows     what the plan has covered, per window, per account
    larameter_deposits    credits in: purchases, gifts, refunds, adjustments
    larameter_usage       credits out, append-only

The balance is stored rather than summed, and once you sell credits that is not an
optimisation but the model itself: a top-up is not consumption, so it cannot be expressed
as a sum of what was spent. With the deposits table it stays checkable all the same, which
is what you want the first time somebody asks why an account has five thousand credits.

## Windows

An allowance is always an allowance per something, and one period is rarely enough. A
monthly figure alone lets a bad afternoon eat the month; a weekly cap on top is the brake.

    'windows' => [
        'session' => ['minutes' => 300, 'anchor' => 'rolling', 'share' => 0.04],
        'weekly'  => ['days' => 7,      'anchor' => 'fixed',   'share' => 0.25],
        'monthly' => ['months' => 1,    'anchor' => 'fixed',   'share' => 1],
    ],

    'pro' => ['credits_monthly' => 50_000],

**A plan grants one figure and every window takes a share of it.** Fifty thousand a month
is twelve thousand five hundred a week and two thousand a sitting, and raising the plan
raises all three. The alternative, a figure per window per plan, is seven plans times
three numbers to keep consistent: the day somebody doubles the monthly and forgets the
weekly, the weekly silently becomes the binding constraint and nothing says so.

The tightest window is the one that binds. Length is built from `minutes`, `hours`, `days`
and `months`, combined. A window with no `share` narrows nothing.

**`anchor` decides when the next window starts**, and the two are not interchangeable:

- **`rolling`** starts the moment credits are next spent after the old one expired, so the
  full length is always available. What a session wants: on a fixed grid, starting ten
  minutes before a boundary hands somebody ten minutes and reads as the product having
  robbed them.
- **`fixed`** sits on a grid laid down from the first window and moves on whether it is
  used or not. What a week wants, because *when does my week reset* needs an answer that
  is not *depends when you last stopped*. A dormant account gets one allowance back on its
  return, not four.

**Asking never opens a window.** For a rolling window the row is the clock, so an expired
one is reported as full without being restarted. Otherwise opening the app to check your
balance would burn the session before a word was typed.

Declare no windows at all and you have opted out of allowance metering: usage is still
recorded, nothing is refused, and only purchased credits mean anything.

## Nothing to synchronise

A window's grid is fixed by when it started, and every reset lands one length later,
for ever. An account whose month began on the 28th resets on the 28th, without anybody
telling it to.

Which is why there is no webhook here, and nothing to call when a subscription renews.
A billing period and a credit window are separate clocks and neither has to know about
the other:

- **The window** resets on its own grid, lazily, the next time credits are charged.
- **The plan** is worked out on every request. Stop paying and the provider stops
  finding a subscription, so the allowance drops to whatever the next provider says.
  Pay four days late and it comes back mid-window.

Tying the two together would break the ordinary case, not improve it: an annual
subscription usually grants a monthly allowance, and a window anchored to the billing
period would reset it once a year.

## Setup

Three steps. No interface to implement, nothing to bind, no column on your table.

    composer require edulazaro/larameter
    php artisan vendor:publish --tag=larameter-config
    php artisan vendor:publish --tag=larameter-migrations

Name your plans in the config:

    'plans' => [
        'free' => ['credits' => ['session' => 100, 'week' => 500, 'month' => 1_000]],
        'pro'  => [
            'credits' => ['session' => 1_000, 'week' => 5_000, 'month' => 10_000],
            'limits'  => ['seats' => 25],
        ],
    ],

Add the trait to whatever you bill:

    class Organization extends Model
    {
        use EduLazaro\Larameter\Concerns\HasCredits;
    }

Done. The account row appears the first time you touch it.

    $org->hasCredits();
    $org->chargeCredits('create_form');
    $org->meterCredits('gpt-4o', 'token', $in, $out);
    $org->creditsRemaining();
    $org->creditsResetAt();

## Plans

Plans are optional. An account with no plan is valid and spends purchased credits only,
which is what you want if you sell bundles rather than subscriptions.

**Define them once, wherever they already live.** Point the package at your own file and
it reads `credits`, `limits` and `features`, ignoring the rest, so the commercial half of
a plan stays next to the metered half:

    // config/larameter.php
    'plans_from' => 'plans.tiers',

    // config/plans.php
    'tiers' => [
        'pro' => [
            'name' => 'Pro',
            'price' => 59_00,
            'stripe_price_id' => env('STRIPE_PRICE_PRO'),

            'credits'  => ['week' => 12_500, 'month' => 50_000],
            'limits'   => ['members' => 1, 'cases' => -1],
            'features' => ['api_access' => false, 'own_cases' => true],
        ],
    ],

**Which plan an account is on is worked out, not stored.** Add `HasPlans` and it is
resolved by a list of providers, tried in order, first answer wins:

    'plan_providers' => [
        PlanProviders\ForcedPlanProvider::class,    // a column of yours, set by hand
        PlanProviders\CashierPlanProvider::class,   // the subscription, by price id
        PlanProviders\StoredPlanProvider::class,    // setCreditPlan(), then the default
    ],

**The order is the policy.** Forced before Cashier means a plan somebody set by hand for a
partner or a demo beats what Stripe thinks, because a person decided it deliberately.

`CashierPlanProvider` is inert without Cashier installed, so leaving it in the list costs
an app that sells credit bundles nothing. For Paddle or anything else, implement
`Contracts\PlanProvider` and add it to the list.

One list for the whole app, because how billing works has one answer per project. Put
`$planProviders` on a model, or call `Model::setPlanProviders()`, only when one model is
billed differently from another.

    $org->plan();                        // a Plan, never null
    $org->plan()->exists;                // false when no provider answered
    $org->plan()->handle;                // 'pro'
    $org->plan()->name;                  // 'Pro'
    $org->plan()->allows('api_access');
    $org->onPlan('pro');

Data is a property, a question is a method. `name` is a property and not a method because
a plan name is a product name: Pro, Max, Hyper Team. Nobody translates those, any more
than they translate the name of the application.

**A `Plan` is generic.** A handle, a name, an allowance and some ceilings, and it reads the
same whether it was resolved from a subscription, from a column of yours, or from a
default. What a provider had to know to answer stays inside that provider.

**Plans are optional.** `HasCredits` alone is an app that sells bundles: no plan, no
allowance, everything from what was purchased.

`setCreditPlan()` remains for the case nothing can resolve: no override column, no
subscription. It is the fallback, not the source.

Changing plan does not restart the windows. An upgrade raises the ceiling over what has
already been spent, rather than handing a second allowance to whoever works out they can
upgrade and downgrade in the same afternoon.

Three defaults that read in different directions, on purpose:

- no `credits` at all means **no allowance**. Credits are what you sell, so a plan that
  does not mention them does not include any.
- a window **missing** from a `credits` map that exists **does not constrain** that plan.
  Saying `'week' => 2000` and nothing else means limited weekly, session free.
- a `limits` key you never listed is **unlimited**. These are restrictions, and a package
  you just installed should not refuse to create users on its own opinion.

## Ceilings

Credits are spent and come back. Seats and projects are different: a standing count of
what exists, and the plan says how many at once. Those are meters.

    php artisan make:meter MemberMeter Organization

    namespace App\Meters\Organization;

    class MemberMeter extends Meter
    {
        protected string $key = 'members';

        public function count(): int
        {
            return $this->meterable->members()->count();
        }
    }

List it on the model:

    class Organization extends Model
    {
        use HasCredits, HasMeters;

        protected array $meters = [MemberMeter::class, CaseMeter::class];
    }

A plain list and not a map, because a meter already knows its own key. Or with the
attribute, the same shape larakeep uses for keepers:

    #[MeteredBy(MemberMeter::class)]
    #[MeteredBy(CaseMeter::class)]
    class Organization extends Model

For a model you cannot edit, a module bringing its own relation, or a meter that only
applies when something is switched on, there is the other half of the pair:

    Organization::meter(MemberMeter::class);

The same arrangement as `$casts` and `mergeCasts()`: the property declares, the call adds.
Doing both with the same meter does not double it.

Then nothing has to remember how to count:

    $org->fits('members');
    $org->usageSummary();

**A meter is a class and not a number you pass in**, and that is the whole point. The app
this came from had a one-seat plan, showed it on the usage screen, and never checked it
when inviting: the cap was enforced for cases and forgotten for members, because enforcing
it meant every caller had to remember to count first.

`label()` is optional and derives from the key. Override it to translate; the package
never sees the string and depends on no translation package.

A resource with no meter is **unlimited**. The other way round, a package you just
installed would start refusing to create things it was never told to count.

## Credits in

    $org->depositCredits(5_000, reason: 'purchase', source: $payment);
    $org->depositCredits(500, reason: 'gift', note: 'launch promo');
    $org->depositCredits(-200, reason: 'adjustment', note: 'duplicate charge');

One call, two tables: the deposit row and the balance move together and cannot be written
apart. Negative is allowed, which is how a correction is written, and the balance clamps at
zero rather than becoming a debt nobody can spend their way out of.

**Purchased credits sit outside every window.** They survive every reset, and — this is
the part that matters — what they pay for is **not counted against the windows**. You run
out of session, you buy more usage, you carry on, and your week has not moved meanwhile.

## Credits out

    $org->chargeCredits('create_form');                  fixed price by name
    $org->meterCredits('gpt-4o', 'token', $in, $out);    priced per unit

Everything is expressed in credits, including the rates:

    'rates' => [
        'gpt-4o' => ['input' => 25_000, 'output' => 100_000],
    ],

25,000 credits per million input tokens. What a credit is worth in money is your business
and the package never asks.

An action you never priced is **free**, not guessed at. A metered unit you never priced
still costs something, because the alternative is that metering an unknown model is free
and the gap only surfaces on your provider's bill.

Rates are indexed directly and not through dot notation, so a model with a dot in its name
(`gpt-5.4`) is priced as itself rather than silently falling through to the wildcard.

Spending draws on the plan allowance first and on purchased credits for the overflow, and
each usage row records that split rather than recomputing it: rates and plans change, and a
bill from last March has to still add up next year. When `credits_from_plan` and
`credits_from_purchased` add up to less than `credits`, the difference is an overdraft,
which is how one stays visible instead of being rounded away.

Writing a row by any other means still moves the balance. Observers keep the two in step,
so a backfill or a console command cannot record consumption nobody is charged for, nor
hand out credits that never reach the balance.

## Asking

    $org->hasCredits()              may it spend?
    $org->creditsRemaining()        headroom plus what was bought
    $org->creditHeadroom()          the plan only, tightest window
    $org->creditAllowanceIn('week') what the plan grants there
    $org->creditsResetAt()          when they can spend again, or null
    $org->plan()->handle            'pro', or '' when there is none

`UsageTracker::hasCreditsMemoized()` answers once per instance, for hot paths that ask
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
