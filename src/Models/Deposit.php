<?php

namespace EduLazaro\Larameter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Credits in: a purchase, a gift, a refund, a correction made by hand.
 *
 * Sits outside every window, so what somebody paid for survives a reset. Also what
 * makes the balance checkable rather than a number to trust.
 *
 * Every deposit written from here on is a LOT: an amount that arrived, may die on a date,
 * and is drawn from directly. `credits` never moves once written; `consumed` is the only
 * mutable part, the way an invoice carries what has been paid against it without rewriting
 * what it was for.
 *
 * `consumed` being NULL is what says "this is not a lot", and it is how rows written
 * before lots existed keep their meaning. Their credits are in purchased_credits on the
 * account and always were, so counting them here as well would double every balance.
 * ALTER TABLE leaves them null on its own, which is why upgrading touches no data at all.
 *
 * That makes the column a closed bucket: it can only drain from here, never grow, and the
 * day it reaches zero it can be dropped without anybody noticing.
 *
 * A NEGATIVE row is a refund or a correction. It is not a lot and is never consumed: it
 * behaves like spending, taken from the lots first and the column after.
 */
class Deposit extends Model
{
    protected $table = 'larameter_deposits';

    protected $fillable = [
        'account_id',
        'credits',
        'reason',
        'source_type',
        'source_id',
        'note',
        'metadata',
        'expires_at',
        'consumed',
    ];

    /**
     * A deposit is a lot unless somebody says otherwise.
     *
     * Zero and not null, so a row written by hand, by a backfill or by an application
     * that never heard of `deposit()` still counts towards the balance. Null has to be
     * asked for, and the only thing that asks for it is ALTER TABLE on rows that predate
     * lots.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consumed' => 0,
    ];

    protected $casts = [
        'credits' => 'integer',
        'consumed' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Lots that still have something in them and have not died.
     *
     * A null `consumed` is not a lot at all: it predates lots, and its credits are in the
     * column. A null `expires_at` is a lot that simply never dies.
     *
     * This is the whole of the expiry feature. A lot stops counting the moment its date
     * passes because of this clause, with no scheduled command to run and no compensating
     * row to write, so there is never a window where the balance is wrong because the
     * sweep has not run yet.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAlive($query)
    {
        return $query->where('credits', '>', 0)
            ->whereNotNull('consumed')
            ->whereColumn('consumed', '<', 'credits')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * The order lots come out in: soonest to die first, oldest to settle a tie, and lots
     * with no date last of all.
     *
     * Spending what would have been lost anyway is the only order that does not cost the
     * account holder something they paid for. What is in the column has no date either,
     * so it is spent after every lot, not among them.
     *
     * Spending what would have been lost anyway is the only order that does not cost the
     * account holder something they paid for.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInSpendingOrder($query)
    {
        return $query->orderByRaw('expires_at IS NULL, expires_at ASC')->orderBy('id');
    }

    /**
     * The same test as the alive() scope, for a lot already in memory.
     *
     * Two definitions of "alive" that drift apart is how a listing and a spend end up
     * disagreeing about a balance, so this is written once beside the scope rather than
     * wherever it happened to be needed.
     *
     * @return bool
     */
    public function isAlive(): bool
    {
        return $this->credits > 0
            && $this->consumed !== null
            && $this->left() > 0
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * What is left in this lot.
     *
     * @return int
     */
    public function left(): int
    {
        return max(0, $this->credits - (int) $this->consumed);
    }

    /**
     * The account this deposit credited.
     *
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Where it came from: a payment, an order, the admin who did it.
     *
     * @return MorphTo
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
