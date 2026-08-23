<?php

namespace EduLazaro\Larameter\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What one account has spent of its plan allowance inside one window.
 *
 * The row is the clock. It is created when credits are first charged against that window
 * and never on a read, which for a rolling window is the whole difference between asking
 * how much you have left and burning five hours by asking.
 */
class Window extends Model
{
    protected $table = 'larameter_windows';

    protected $fillable = [
        'account_id',
        'key',
        'credits_used',
        'started_at',
    ];

    protected $casts = [
        'credits_used' => 'integer',
        'started_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    // ─── Definition ─────────────────────────────────────────────────

    /** @return array<string, array> */
    public static function declared(): array
    {
        return config('larameter.windows') ?? [];
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        return static::declared()[$key] ?? [];
    }

    public static function lengthOf(string $key): CarbonInterval
    {
        $d = static::definition($key);

        $units = [
            'months' => (int) ($d['months'] ?? 0),
            'days' => (int) ($d['days'] ?? 0),
            'hours' => (int) ($d['hours'] ?? 0),
            'minutes' => (int) ($d['minutes'] ?? 0),
        ];

        // A window of no length would make the fixed-anchor advance spin forever looking
        // for a period that ends after now. Fail here instead, where the message can name
        // the window you got wrong.
        if (array_sum($units) <= 0) {
            throw new InvalidArgumentException(
                "larameter: window [{$key}] has no length. Give it minutes, hours, days or months.",
            );
        }

        return CarbonInterval::months($units['months'])
            ->days($units['days'])
            ->hours($units['hours'])
            ->minutes($units['minutes']);
    }

    /** 'rolling' or 'fixed'. See the config for what each one is for. */
    public static function anchorOf(string $key): string
    {
        return static::definition($key)['anchor'] ?? 'fixed';
    }

    // ─── State ──────────────────────────────────────────────────────

    public function endsAt(): Carbon
    {
        return $this->started_at->copy()->add(static::lengthOf($this->key));
    }

    public function isExpired(): bool
    {
        // Not isPast(): the exact boundary instant belongs to the new window, not to the
        // one that has just run out.
        return ! $this->endsAt()->isFuture();
    }

    /** What the plan has covered in the window that is running now. */
    public function currentUsage(): int
    {
        return $this->isExpired() ? 0 : $this->credits_used;
    }

    /**
     * Open the window that is running now, wiping the count.
     *
     * Call this only when credits are actually being spent. On a rolling window it starts
     * the clock, and starting it costs the user real time.
     */
    public function restart(): void
    {
        $this->credits_used = 0;

        if (static::anchorOf($this->key) === 'rolling') {
            // The full length, from the moment they came back.
            $this->started_at = now();

            return;
        }

        // Fixed: the grid was laid down long ago. Walk it to the period covering now,
        // one at a time, so an account dormant for four weeks gets one allowance back
        // and not four.
        $length = static::lengthOf($this->key);
        $start = $this->started_at->copy();

        while (! $start->copy()->add($length)->isFuture()) {
            $start->add($length);
        }

        $this->started_at = $start;
    }
}
