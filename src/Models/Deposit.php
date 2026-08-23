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
