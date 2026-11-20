<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use SorgeIt\HttpServer\Body;
use ValueError;

/**
 * The body of a request the server read: over the `BodyReader` of its connection, or held whole
 * once read before the handler.
 *
 * @internal
 */
final class RequestBody implements Body
{
    private ?string $error = null;

    /**
     * The string of the first cast, as a second cast reads nothing more.
     */
    private ?string $whole = null;

    /**
     * The bytes read before the handler, the offset of the next read, and the error after them,
     * as the `io.MultiReader()` a handler of Go gives on after `MaxBytesReader`.
     */
    private ?string $held = null;

    private int $at = 0;

    private ?string $heldError = null;

    /**
     * Go closes the body once the handler returned, also one read before it.
     */
    private bool $closed = false;

    public function __construct(private ?BodyReader $reader) {}

    public function __toString(): string
    {
        if ($this->whole === null) {
            [$this->whole, $error] = match (true) {
                $this->closed => ['', Go::READ_AFTER_CLOSE],
                $this->reader instanceof BodyReader => $this->reader->readAll($this),
                default => [substr((string) $this->held, $this->at), $this->heldError],
            };
            $this->at = strlen((string) $this->held);
            $this->error ??= $error;
        }

        return $this->whole;
    }

    /**
     * The empty read of a valid length, so each body refuses a length below 1 alike.
     *
     * @throws ValueError
     */
    public static function check(int $length): string
    {
        if ($length < 1) {
            throw new ValueError(sprintf('%s::read(): Argument #1 ($length) must be greater than 0', Body::class));
        }

        return '';
    }

    /**
     * Keeps the body read before the handler; the reader goes on with the next request.
     */
    public function hold(string $bytes, ?string $error): void
    {
        $this->held = $bytes;
        $this->heldError = $error;
        $this->reader = null;
    }

    /**
     * Reads the body as a handler of Go through `MaxBytesReader` first, and holds what it read;
     * false for a body over the limit.
     */
    public function readUpTo(int $limit): bool
    {
        // The handler gets the body only after this read.
        assert($this->reader instanceof BodyReader);
        [$bytes, $error] = $this->reader->readAll($this, $limit);

        if ($error === Go::TOO_LARGE) {
            return false;
        }

        $this->hold($bytes, $error);

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->held = null;
    }

    public function read(int $length): string
    {
        self::check($length);

        if ($this->closed) {
            [$bytes, $error] = ['', Go::READ_AFTER_CLOSE];
        } elseif ($this->reader instanceof BodyReader) {
            [$bytes, $error] = $this->reader->read($this, $length);
        } else {
            $bytes = substr((string) $this->held, $this->at, $length);
            $this->at += strlen($bytes);
            $error = $bytes === '' ? $this->heldError : null;
        }

        // Kept: after `unexpected EOF` of a body of a length, the next read of Go gives EOF.
        $this->error ??= $error === ReadBuffer::EOF ? null : $error;

        return $bytes;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
