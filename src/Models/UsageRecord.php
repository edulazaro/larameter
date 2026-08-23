<?php

namespace EduLazaro\Larameter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of consumption. Append-only, and never the source of the balance.
 *
 * The balance lives on the Account. This table is what you audit with, invoice from and
 * reconcile against when the two disagree. A consequence worth knowing: deleting rows
 * here does NOT hand anybody their credits back, which is the right way round.
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
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
