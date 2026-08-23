<?php

namespace EduLazaro\Larameter;

use Illuminate\Support\Carbon;

/**
 * What one window of an account looks like right now: what the plan grants there, how
 * much of it has gone, and when it starts over.
 *
 * Read-only by construction, because reading a window must never open one. On a rolling
 * window the row is the clock, so creating it to answer a question would spend five
 * hours of somebody's session on a page they opened to look at their balance.
 */
class WindowUsage
{
    /**
     * Describe one window.
     *
     * @param string $key
     * @param int $allowance
     * @param int $used
     * @param Carbon|null $endsAt
     * @return void
     */
    public function __construct(
        public readonly string $key,
        private readonly int $allowance,
        private readonly int $used,
        private readonly ?Carbon $endsAt,
    ) {
    }

    /**
     * What the plan grants here, before anything is spent of it.
     *
     * @return int
     */
    public function allowance(): int
    {
        return $this->allowance;
    }

    /**
     * What the plan has covered in the window running now.
     *
     * Zero for a window that has not started, and zero again once it has run out: an
     * expired window is reported as full rather than restarted.
     *
     * @return int
     */
    public function used(): int
    {
        return $this->used;
    }

    /**
     * What is left of the allowance here.
     *
     * @return int
     */
    public function remaining(): int
    {
        return $this->isUnlimited() ? PHP_INT_MAX : max(0, $this->allowance - $this->used);
    }

    /**
     * Whether the plan puts no ceiling on this window.
     *
     * @return bool
     */
    public function isUnlimited(): bool
    {
        return $this->allowance < 0;
    }

    /**
     * When the window running now starts over.
     *
     * Null when no window is running: nothing has been spent yet, or a rolling one has
     * expired and the next begins whenever spending resumes.
     *
     * @return Carbon|null
     */
    public function endsAt(): ?Carbon
    {
        return $this->endsAt;
    }

    /**
     * How much of the allowance has gone, as a percentage. Zero when unlimited.
     *
     * @return float
     */
    public function percentUsed(): float
    {
        if ($this->isUnlimited() || $this->allowance <= 0) {
            return 0.0;
        }

        return min(100.0, ($this->used / $this->allowance) * 100);
    }

    /**
     * The window as a row for a usage screen.
     *
     * @return array{key: string, allowance: int, used: int, remaining: int, ends_at: Carbon|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'allowance' => $this->allowance,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'ends_at' => $this->endsAt,
        ];
    }
}
