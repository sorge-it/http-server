<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Fiber;

/**
 * The bytes of the client on a connection that no request took yet. Where a read waits for more,
 * the fiber of the parser waits for `give()`.
 *
 * @internal
 */
final class ReadBuffer
{
    /**
     * The end of a body of a length.
     */
    public const string EOF = 'EOF';

    /**
     * Bytes of the client from `$at` that no request took.
     */
    private string $in = '';

    private int $at = 0;

    /**
     * Bytes the requests took in all.
     */
    private int $taken = 0;

    private bool $ended = false;

    /**
     * The error of the close of the server that ended the bytes, or null for any other end.
     */
    private ?string $error = null;

    /**
     * A read waits in a fiber for more bytes of the client.
     */
    private bool $waiting = false;

    public function give(string $bytes): void
    {
        // The bytes read go only once they are half of the buffer: a copy of the rest at each
        // piece would make a long line that comes in small pieces quadratic.
        if ($this->at > 0 && $this->at >= strlen($this->in) >> 1) {
            $this->in = substr($this->in, $this->at);
            $this->at = 0;
        }

        $this->in .= $bytes;
    }

    /**
     * Each read after the bytes that came ends, with the error where one is given.
     */
    public function end(?string $error = null): void
    {
        $this->ended = true;
        $this->error = $error;
    }

    public function waiting(): bool
    {
        return $this->waiting;
    }

    /**
     * The error of a read that met the end: `unexpected EOF`, or that of the close.
     */
    public function unexpectedEnd(): string
    {
        return $this->error ?? Go::UNEXPECTED_EOF;
    }

    /**
     * Whether the connection holds bytes of the client that no request took.
     */
    public function holds(): bool
    {
        return $this->at < strlen($this->in);
    }

    /**
     * The count of bytes of the client before the next byte to read.
     */
    public function offset(): int
    {
        return $this->taken;
    }

    /**
     * Waits for a byte, and gives false at the end.
     */
    public function next(): bool
    {
        return $this->holds() || $this->more();
    }

    /**
     * At most the bytes asked for, at least one; false at the end.
     */
    public function read(int $size): string|false
    {
        if (! $this->next()) {
            return false;
        }

        $bytes = substr($this->in, $this->at, $size);
        $this->at += strlen($bytes);
        $this->taken += strlen($bytes);

        return $bytes;
    }

    /**
     * The bytes up to and with the next LF, within the most bytes: null where that many came
     * without an LF; the rest where the end came first.
     */
    public function line(int $most): ?string
    {
        $searched = 0;

        // Only the bytes that came since the last search are searched, so a line that comes byte
        // by byte takes linear time.
        while (($found = strpos($this->in, "\n", $this->at + $searched)) === false || $found - $this->at >= $most) {
            if (strlen($this->in) - $this->at >= $most) {
                return null;
            }

            $searched = strlen($this->in) - $this->at;

            if (! $this->more()) {
                return (string) $this->read($most);
            }
        }

        return (string) $this->read($found - $this->at + 1);
    }

    /**
     * Waits until more bytes came, and gives false at the end.
     */
    private function more(): bool
    {
        $held = strlen($this->in) - $this->at;

        while (strlen($this->in) - $this->at === $held) {
            if ($this->ended) {
                return false;
            }

            $this->waiting = true;
            Fiber::suspend();
            $this->waiting = false;
        }

        return true;
    }
}
