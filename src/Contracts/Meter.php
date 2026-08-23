<?php

namespace EduLazaro\Larameter\Contracts;

/**
 * A countable resource a plan puts a ceiling on: seats, cases, projects.
 *
 * A different question from credits: those are spent and come back, these are a
 * standing count of what exists.
 *
 * Extend EduLazaro\Larameter\Meter rather than implementing this. An implementation
 * also needs a public string $handle, which cannot be required here because a PHP
 * interface could not declare a property until 8.4.
 */
interface Meter
{
    /**
     * Display name for a usage screen.
     *
     * @return string
     */
    public function label(): string;

    /**
     * How many exist right now.
     *
     * @return int
     */
    public function count(): int;
}
