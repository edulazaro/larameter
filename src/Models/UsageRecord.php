<?php

namespace EduLazaro\Larameter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of consumption. Append-only.
 *
 * The balance is not stored anywhere: it is this table summed over a window and compared
 * against the plan. That is a deliberate trade. A stored balance is one number that can
 * drift out of step with the rows that produced it, and when it does you cannot tell
 * which is wrong. Summing is slower and always right, and at the volumes one account
 * produces the index carries it.
 *
 * If you outgrow that, the fix is a periodic rollup row, not a mutable balance.
 */
class UsageRecord extends Model
{
    protected $table = 'larameter_usage';

    protected $fillable = [
        'account_type',
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
        'cost',
        'metadata',
    ];

    protected $casts = [
        'quantity_in' => 'integer',
        'quantity_out' => 'integer',
        'credits' => 'integer',
        'cost' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function account(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who triggered it, when there was somebody. Null for scheduled work. */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** What it was about: a case, a document, a conversation. For tracing spend back. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
