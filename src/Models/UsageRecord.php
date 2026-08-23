<?php

namespace EduLazaro\Larameter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of consumption. Append-only, and never the source of the balance.
 *
 * The balance lives on the Account; this is what you audit with, invoice from and
 * reconcile against. Deleting rows here does not hand anybody their credits back.
 *
 * The split between plan and purchased is recorded rather than recomputed, because
 * rates and plans change and an old bill still has to add up. The two adding to less
 * than `credits` is an overdraft.
 */
class UsageRecord extends Model
{
    protected $table = 'larameter_usage';

    protected $fillable = [
        'account_id',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'operation',
        'unit',
        'quantity_in',
        'quantity_out',
        'credits',
        'credits_from_plan',
        'credits_from_purchased',
        'metadata',
    ];

    protected $casts = [
        'quantity_in' => 'integer',
        'quantity_out' => 'integer',
        'credits' => 'integer',
        'credits_from_plan' => 'integer',
        'credits_from_purchased' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * The account this usage was charged to.
     *
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Who triggered it. Null for scheduled work.
     *
     * @return MorphTo
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What it was about, for tracing spend back to it.
     *
     * @return MorphTo
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
