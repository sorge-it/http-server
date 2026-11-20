<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use SorgeIt\HttpServer\Body;

/**
 * `http.NoBody` of Go: the body of a request that no server read, such as one a test makes.
 *
 * @internal
 */
final readonly class NoBody implements Body
{
    public function __toString(): string
    {
        return '';
    }

    public function read(int $length): string
    {
        return RequestBody::check($length);
    }

    public function error(): ?string
    {
        return null;
    }
}
