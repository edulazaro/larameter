<?php

namespace EduLazaro\Larameter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Credits in: a purchase, a welcome gift, a refund, a correction made by hand.
 *
 * These sit outside every window. That is the point of them, and it is what lets somebody
 * who has run out of plan allowance carry on working.
 *
 * With this table the balance stops being a number you have to trust and becomes one you
 * can check.
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
    ];

    protected $casts = [
        'credits' => 'integer',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** The Stripe payment, the order, the admin who did it. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
