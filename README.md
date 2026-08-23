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

An allowance is always an allowance PER something, and the shape most people know is the
one Claude uses: a **five-hour session** that starts when you first write, and a **weekly**
cap on top of it. Billing is monthly; usage is not.

    'windows' => [
        'session' => ['minutes' => 300, 'anchor' => 'rolling'],
        'week'    => ['days' => 7,      'anchor' => 'fixed'],
    ],

The tightest window is the one that binds. Length is built from `minutes`, `hours`, `days`
and `months`, combined.

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

## Renewal

Stripe bills by anniversary unless you tell it otherwise: subscribe on the 28th and your
cycle runs 28th to 28th. So the grid has to be the customer's, not the calendar's.
Anchoring credits to the 1st while charging on the 28th hands every new account an extra
allowance, and it always falls the customer's way, so nobody ever reports it.

Fixed windows lay their grid on first USE, which is right for an account that never pays.
Once money is involved, line them up with the invoice:

    $org->startCreditPeriod($subscription->asStripeSubscription()->current_period_start);

Call it when they first pay and on every renewal. **Never on a plan change**: upgrade, new
allowance, downgrade, repeat is the door this package keeps shut, and `setCreditPlan()`
deliberately does not touch it.

Two things make it hard to misuse anyway. Rolling windows are left alone, because a
session is not a billing period and a renewal has no business handing back the five hours
somebody just spent. And passing the same instant twice does nothing, so a webhook
delivered twice cannot be replayed for a second allowance.

## Setup

Three steps. No interface to implement, nothing to bind, no column on your table.

    composer require edulazaro/larameter
    php artisan vendor:publish --tag=larameter-config
    php artisan vendor:publish --tag=larameter-migrations

Name your plans in the config:

    'plans' => [
        'free' => ['credits' => ['session' => 50, 'week' => 200]],
        'pro'  => [
            'credits' => ['session' => 500, 'week' => 5_000],
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
    $org->startCreditPeriod($renewedAt);
    $org->canCreate('seats', $current);

## Plans

Plans are optional. An account with no plan is valid and spends purchased credits only,
which is what you want if you sell bundles rather than subscriptions.

    $org->setCreditPlan('pro');    // your subscription changed
    $org->setCreditPlan(null);     // cancelled

The package cannot know when your plan changes, so it does not guess. Call `setCreditPlan`
from wherever you do know: an observer on Cashier's `Subscription` model, an admin action,
a grant. Be aware that a **trial lapsing fires no event at all**, so if your plans can
expire by the clock alone, something has to notice.

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

An action you never priced is **free**, not guessed at. A metered unit you never priced
still costs something, because the alternative is that metering an unknown model is free
and the gap only surfaces on your provider's invoice.

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
    $org->creditPlan()              the plan key, or null

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
