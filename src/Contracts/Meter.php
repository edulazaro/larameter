<?php

namespace EduLazaro\Larameter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Something countable that a plan puts a ceiling on: seats, cases, projects.
 *
 * A different question from credits. Credits are spent and come back; these are a
 * standing count of what exists, and the plan says how many you may have at once.
 *
 * Extend {@see \EduLazaro\Larameter\Meter} rather than implementing this: the base class
 * leaves you one method to write. The interface is here for a model with a class
 * hierarchy of its own.
 */
interface Meter
{
    /** Matches the key under `limits` in the plan. */
    public function key(): string;

    /** For a usage screen. */
    public function label(): string;

    /** How many exist right now. */
    public function count(): int;
}
