<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use SorgeIt\HttpServer\Internal\RequestBody;

/**
 * A body with fixed bytes, for a `Request` built in code, such as in a handler's unit test. It
 * reads as a body that has arrived whole and has no error.
 *
 * @api
 */
final class StringBody implements Body
{
    private int $at = 0;

    private ?string $whole = null;

    public function __construct(private readonly string $bytes) {}

    public function __toString(): string
    {
        if ($this->whole === null) {
            $this->whole = substr($this->bytes, $this->at);
            $this->at = strlen($this->bytes);
        }

        return $this->whole;
    }

    public function read(int $length): string
    {
        $bytes = RequestBody::check($length).substr($this->bytes, $this->at, $length);
        $this->at += strlen($bytes);

        return $bytes;
    }

    public function error(): ?string
    {
        return null;
    }
}
