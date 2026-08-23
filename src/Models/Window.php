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
 * The row is the clock: created when credits are first charged against that window and
 * never on a read. For a rolling window that is the difference between asking how much
 * is left and spending five hours by asking.
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

    /**
     * The account this window belongs to.
     *
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Every window declared in config.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function declared(): array
    {
        return config('larameter.windows') ?? [];
    }

    /**
     * One window's declaration.
     *
     * @param string $key
     * @return array<string, mixed>
     */
    public static function definition(string $key): array
    {
        return static::declared()[$key] ?? [];
    }

    /**
     * How long a window lasts.
     *
     * @param string $key
     * @return CarbonInterval
     * @throws InvalidArgumentException When the window declares no length.
     */
    public static function lengthOf(string $key): CarbonInterval
    {
        $d = static::definition($key);

        $units = [
            'months' => (int) ($d['months'] ?? 0),
            'days' => (int) ($d['days'] ?? 0),
            'hours' => (int) ($d['hours'] ?? 0),
            'minutes' => (int) ($d['minutes'] ?? 0),
        ];
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

    /**
     * Whether the next window starts on next use, or on a fixed grid.
     *
     * @param string $key
     * @return string
     */
    public static function anchorOf(string $key): string
    {
        return static::definition($key)['anchor'] ?? 'fixed';
    }

    /**
     * When the current window runs out.
     *
     * @return Carbon
     */
    public function endsAt(): Carbon
    {
        return $this->started_at->copy()->add(static::lengthOf($this->key));
    }

    /**
     * Whether the window has run out.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return ! $this->endsAt()->isFuture();
    }

    /**
     * What the plan has covered in the window running now.
     *
     * @return int
     */
    public function currentUsage(): int
    {
        return $this->isExpired() ? 0 : $this->credits_used;
    }

    /**
     * Open the window running now, wiping the count.
     *
     * Only when credits are actually being spent: on a rolling window this starts the
     * clock, and starting it costs the user real time.
     *
     * @return void
     */
    public function restart(): void
    {
        $this->credits_used = 0;

        if (static::anchorOf($this->key) === 'rolling') {
            // The full length, from the moment they came back.
            $this->started_at = now();

            return;
        }
        $this->started_at = $this->currentStart();
    }

    /**
     * When the window running now starts over, or null when none is running.
     *
     * Not the same as endsAt() once a fixed window has expired: the grid has gone on
     * without it, so the row says a Monday three weeks ago while the answer the screen
     * needs is the Monday coming. A rolling one is not running at all until the next
     * charge, so it has no end yet.
     *
     * @return Carbon|null
     */
    public function currentEndsAt(): ?Carbon
    {
        if (! $this->isExpired()) {
            return $this->endsAt();
        }

        if (static::anchorOf($this->key) === 'rolling') {
            return null;
        }

        return $this->currentStart()->add(static::lengthOf($this->key));
    }

    /**
     * When the window running now began, or null when none is.
     *
     * @return Carbon|null
     */
    public function currentStartedAt(): ?Carbon
    {
        if (! $this->isExpired()) {
            return $this->started_at;
        }

        return static::anchorOf($this->key) === 'rolling' ? null : $this->currentStart();
    }

    /**
     * The start of the slot on the grid that contains now.
     *
     * @return Carbon
     */
    protected function currentStart(): Carbon
    {
        $length = static::lengthOf($this->key);
        $start = $this->started_at->copy();

        while (! $start->copy()->add($length)->isFuture()) {
            $start->add($length);
        }

        return $start;
    }
}
