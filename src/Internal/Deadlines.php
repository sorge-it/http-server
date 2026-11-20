<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use SorgeIt\HttpServer\Limits;

/**
 * The times of one connection, in the clock of its server, and when the connection is past its
 * time.
 *
 * @internal
 */
final class Deadlines
{
    /**
     * The time at which the connection closes at the latest, once its answer is written and its
     * writing end is shut; null until then.
     */
    private ?int $lingerUntil = null;

    /**
     * The accept, or the first byte after a pause of the requests; null in such a pause.
     */
    private ?int $begun;

    /**
     * The request in progress is in its head.
     */
    private bool $head = true;

    /**
     * The time at which the last byte was written, or the connection was accepted.
     */
    private int $wrote;

    public function __construct(private readonly Clock $clock, private readonly Limits $limits)
    {
        $this->begun = $clock->now();
        $this->wrote = $this->begun;
    }

    public function lingering(): bool
    {
        return $this->lingerUntil !== null;
    }

    /**
     * Whether the parser reads a request, and in it the head: the time of a head begins with its
     * request, and ends when the head is complete or refused.
     */
    public function request(bool $reading, bool $head): void
    {
        $this->begun = $reading ? $this->begun ?? $this->clock->now() : null;
        $this->head = $head;
    }

    public function wrote(): void
    {
        $this->wrote = $this->clock->now();
    }

    public function linger(): void
    {
        $this->lingerUntil = $this->clock->now() + Go::LINGER;
    }

    /**
     * When the connection is past its time, or null. The first that applies: the end of the linger;
     * none while answers are to be written; `headerTimeout` for a head, and none for a body; else
     * `idleTimeout`.
     */
    public function due(bool $reads, bool $writing): ?int
    {
        if ($this->lingerUntil !== null) {
            return $this->lingerUntil;
        }

        // A client that takes its answer slowly keeps the connection; the idle time runs after it.
        if ($writing) {
            return null;
        }

        if ($reads && $this->begun !== null) {
            return $this->head ? $this->after($this->begun, $this->limits->headerTimeout) : null;
        }

        return $this->after($this->wrote, $this->limits->idleTimeout);
    }

    /**
     * 0 means no deadline, not one that is due at once, as `Limits` takes 0 for off.
     */
    private function after(int $start, float $seconds): ?int
    {
        return $seconds > 0 ? $start + (int) ($seconds * 1e9) : null;
    }
}
