<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * The time of one server, in nanoseconds of `hrtime()`. A test moves it ahead instead of waiting
 * for a timeout, or back so that a time does not end; the time of other servers stays.
 *
 * @internal
 */
final class Clock
{
    /**
     * The most seconds a timeout or a tick may be: the most `select()` of macOS waits, and as
     * nanoseconds they fit an int, also added to the time.
     */
    public const float MAX_SECONDS = 1e8;

    private int $ahead = 0;

    public function now(): int
    {
        return (int) hrtime(true) + $this->ahead;
    }

    /**
     * For the tests: a time limit passes without a wait, or, for a negative value, gets more time.
     */
    public function advance(float $seconds): void
    {
        $this->ahead += (int) ($seconds * 1e9);
    }
}
