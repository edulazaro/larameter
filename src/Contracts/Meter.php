<?php

namespace EduLazaro\Larameter\Contracts;

/**
 * Something countable that a plan puts a ceiling on: seats, cases, projects.
 *
 * A different question from credits. Credits are spent and come back; these are a
 * standing count of what exists, and the plan says how many you may have at once.
 *
 * Extend {@see \EduLazaro\Larameter\Meter} rather than implementing this: the base class
 * leaves you one method to write and works the handle out from the class name. The
 * interface is here for a class hierarchy of your own, and an implementation of it also
 * needs a public string $handle. That cannot be required here, because a PHP interface
 * could not declare a property until 8.4 and this package supports 8.2.
 */
interface Meter
{
    /** For a usage screen. */
    public function label(): string;

    /** How many exist right now. */
    public function count(): int;
}
