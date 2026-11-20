<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use LogicException;
use Stringable;
use ValueError;

/**
 * The body of a request, read as the handler asks for it, like Go's `Request.Body`. Without
 * `maxBody`, the server holds at most 64 KiB that the handler has not read yet.
 *
 * @api
 */
interface Body extends Stringable
{
    /**
     * Reads the rest of the body once it has arrived, like Go's `io.ReadAll()`. Later casts return
     * the same string. Go has no such cast: it is the short form for a handler that needs the
     * whole body.
     */
    public function __toString(): string;

    /**
     * At most `$length` bytes, like one Go `Read()`: '' at the end, or after an error that
     * `error()` names. A read before more bytes have arrived waits for them.
     *
     * @param  positive-int  $length
     *
     * @throws ValueError for a length below 1
     * @throws LogicException while the handler runs, in a fiber other than the server's
     */
    public function read(int $length): string;

    /**
     * Go's first error of a read, such as `unexpected EOF`; null while there is none.
     */
    public function error(): ?string;
}
